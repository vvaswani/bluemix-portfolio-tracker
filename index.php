<?php
// use Composer autoloader
require 'vendor/autoload.php';
require 'config.php';

// load classes
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Silex\Application;

// initialize Silex application
$app = new Application();

// load configuration from file
$app->config = $config;

// add configuration for HybridAuth
$app->config['hybridauth']  = array(
  "base_url" => 'http://'.$_SERVER['HTTP_HOST']. '/callback',
  "providers" => array (
  "Google" => array (
    "enabled" => true,
    "keys" => array (
      "id" => $app->config['oauth_id'], 
      "secret" => $app->config['oauth_secret'] 
    ),
    "scope" => "https://www.googleapis.com/auth/userinfo.email"
)));

// register Twig template provider
$app->register(new Silex\Provider\TwigServiceProvider(), array(
  'twig.path' => __DIR__.'/views',
));

// register URL generator
$app->register(new Silex\Provider\UrlGeneratorServiceProvider());

// if BlueMix VCAP_SERVICES environment available
// overwrite local database credentials with BlueMix credentials
if ($services = getenv("VCAP_SERVICES")) {
  $services_json = json_decode($services, true);
  $app->config['db_uri'] = $services_json['cloudantNoSQLDB'][0]['credentials']['url'];
} 

// initialize HybridAuth client
$auth = new Hybrid_Auth($app->config['hybridauth']);

// start session
session_start();

// register authentication middleware
$authenticate = function (Request $request, Application $app) use ($config) {
  if (!isset($_SESSION['uid'])) {
    return $app->redirect($app["url_generator"]->generate('login'));
  }    
};


// initialize HTTP client
$guzzle = new GuzzleHttp\Client([
  'base_uri' => $app->config['db_uri'] . '/',
  'verify' => false
]);

// index page handlers
$app->get('/', function () use ($app) {
  return $app->redirect($app["url_generator"]->generate('index') . '#search');
});

$app->get('/index', function () use ($app, $guzzle) {
  $uid = $_SESSION['uid'];
  // get all stocks in user's portfolio
  $response = $guzzle->get($app->config['db_name'] . '/_design/users/_search/searchByUID?include_docs=true&q='.urlencode($uid));
  $list = json_decode((string)$response->getBody());
  $symbols = [];
  $data = [];
  // extract unique list of stocks
  foreach ($list->rows as $row) {
    $symbol = $row->doc->symbol;
    $symbols[$symbol] = 0;
  }
  // get closing price for each stock
  foreach ($symbols as $key => &$value) {
    $url = "https://www.quandl.com/api/v1/datasets/WIKI/$key.json?api_key=" .$app->config['quandl_key'] . "&rows=1";
    $response = $guzzle->get($url);
    $eod = json_decode((string)$response->getBody()); 
    $idx = array_search('Close', $eod->column_names);
    $price = $eod->data[0][$idx];
    $value = $price;
  }
  // create structured array of stocks, prices and valuations
  foreach ($list->rows as $row) {
    $id = $row->doc->_id;
    $rev = $row->doc->_rev;
    $rid = "$id.$rev";
    $symbol = $row->doc->symbol;
    $units = $row->doc->units;
    $price = $symbols[$symbol];
    $data[$rid] = [
      'symbol' => $symbol,
      'units' => $units,
      'price' => $price,
      'value' => $units * $price,
    ];
  }
  return $app['twig']->render('index.twig', array('data' => $data, 'uid' => $uid));
})
->before($authenticate)
->bind('index');

$app->get('/search/{query}', function ($query) use ($app, $guzzle) {
  // execute search on Quandl API
  // specify search scope and required response fields 
  $response = $guzzle->get('https://www.quandl.com/api/v2/datasets.json?api_key=' . $app->config['quandl_key'] . '&source_code=WIKI&query='.urlencode($query));
  $result = $response->getBody();
  // remove unwanted trailing strings
  $result = str_replace(' Prices, Dividends, Splits and Trading Volume', '', $result);
  if ($result) {
    return new Response($result, Response::HTTP_OK, array('content-type' => 'application/json'));
  } else {
    return new Response(null, Response::HTTP_NOT_FOUND);  
  }
})
->before($authenticate);

$app->get('/add/{symbol}', function ($symbol) use ($app) {
  return $app['twig']->render('add.twig', array('symbol' => $symbol));
})
->before($authenticate);

$app->post('/add', function (Request $request) use ($app, $guzzle) {
  $symbol = strip_tags($request->get('symbol'));
  $units = (int)$request->get('units');
  if ($units <= 0) {
    throw new Exception('Invalid input');
  }
  $doc = [
    'uid' => $_SESSION['uid'],
    'symbol' => $symbol,
    'units' => $units
  ];
  $guzzle->post($app->config['db_name'], [ 'json' => $doc ]);
  return $app->redirect($app["url_generator"]->generate('index') . '#manage');
})
->before($authenticate);


$app->get('/delete/{rid}', function ($rid) use ($app, $guzzle) {
  $arr = explode('.', $rid);
  $id = $arr[0];
  $rev = $arr[1];
  $guzzle->delete($app->config['db_name'] . '/' . $id . '?rev=' . $rev);
  return $app->redirect($app["url_generator"]->generate('index') . '#manage');
})
->before($authenticate);

// login handler
// check if authenticated against provider
// retrieve user email address and save to session
$app->get('/login', function () use ($app, $auth) {
  $google = $auth->authenticate("Google");
  $currentUser = $google->getUserProfile();
  $_SESSION['uid'] = $currentUser->email;
  return $app->redirect($app["url_generator"]->generate('index') . '#search');
})
->bind('login');

// logout handler
// log out and display logout information page
$app->get('/logout', function () use ($app, $auth) {
  $auth->logoutAllProviders();
  session_destroy();
  return $app['twig']->render('logout.twig');
})
->before($authenticate);

// OAuth callback handler
$app->get('/callback', function () {
  return Hybrid_Endpoint::process();
});

// legal page
$app->get('/legal', function () use ($app) {
  return $app['twig']->render('legal.twig');
});

// delete-my-data handler
$app->get('/delete-my-data', function () use ($app, $guzzle) {
  $uid = $_SESSION['uid'];
  // get all docs for user
  $response = $guzzle->get($app->config['db_name'] . '/_design/users/_search/searchByUID?include_docs=true&q='.urlencode($uid));
  $list = json_decode((string)$response->getBody());
  foreach ($list->rows as $row) {
    $id = $row->doc->_id;
    $rev = $row->doc->_rev;
    $guzzle->delete($app->config['db_name'] . '/' . $id . '?rev=' . $rev);
  }
  return $app->redirect($app["url_generator"]->generate('index') . '#search');
})
->before($authenticate);

// error page handler
$app->error(function (\Exception $e, $code) use ($app) {
  return $app['twig']->render('error.twig', array('error' => $e->getMessage()));
});

$app->run();
