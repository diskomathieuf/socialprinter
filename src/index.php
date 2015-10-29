<?php
require_once __DIR__ . '/../vendor/autoload.php';

$app = new Silex\Application();
if ($_SERVER['HTTP_HOST'] === 'socialprinter.localhost') $app['debug'] = true;

$app['insta'] = new MetzWeb\Instagram\Instagram(array(
    'apiKey'      => '8fdab76ee73e41a18e8d6286021c69f6',
    'apiSecret'   => '87c023f0969f4134a7a97727a880e595',
    'apiCallback' => 'http://socialprinter.localhost/choose-color'
));

$app->register(new Silex\Provider\UrlGeneratorServiceProvider());
$app->register(new Silex\Provider\SessionServiceProvider());
$app->register(new Silex\Provider\TwigServiceProvider(), array(
    'twig.path' => __DIR__ . '/templates',
));

$app['twig'] = $app->share($app->extend('twig', function($twig, $app) {
    $twig->addFunction(new \Twig_SimpleFunction('asset', function ($asset) use ($app) {
        return sprintf('%s/%s', trim($app['request']->getBasePath()), ltrim($asset, '/'));
    }));
    return $twig;
}));

// ROUTES
$app->get('/', function (Silex\Application $app) {
    $loginUrl = $app['insta']->getLoginUrl();

    return $app['twig']->render('index.html.twig',
        array(
            'loginUrl' => $loginUrl,
        )
    );
})
    ->bind('index');

$app->get('/choose-color', function (Silex\Application $app) {
    // receive OAuth code parameter
    $code = $_GET['code'];

    // check whether the user has granted access
    if (isset($code)) {
        // receive OAuth token object
        $data = $app['insta']->getOAuthToken($code);

        if (isset($data->access_token)) {
            $app['session']->set('access_token', $data->access_token);
            $app['insta']->setAccessToken($data);
        } else {
            $app['insta']->setAccessToken($app['session']->get('access_token'));
        }

        // store user access token
        $result = $app['insta']->getUserMedia('self', 50);
        $data = $result->data;

        $medias = array();
        $dominantColors = array();
        $i = 0;
        if (!empty($data)) {
            while (isset($data[$i]) && count($medias) < 4) {
                if ($data[$i]->type === 'image') {
                    $currentImageDominantColors = ColorThief\ColorThief::getPalette($data[$i]->images->standard_resolution->url, 3);
                    $currentImageDominantColors = array_slice($currentImageDominantColors, 0, 3);
                    $colors = array();
                    foreach ($currentImageDominantColors as $color) {
                        $colors[] = join(',', $color);
                    }
                    $dominantColors[$data[$i]->id] = $colors;
                    $data[$i]->colors = $colors;
                    $medias[] = $data[$i];
                }
                $i++;
            }
            $app['session']->set('dominant_colors', $dominantColors);
        }
    } else {
        // check whether an error occurred
        if (isset($_GET['error'])) {
            echo 'An error occurred: ' . $_GET['error_description'];
        }
    }

    return $app['twig']->render(
        'choose-color.html.twig',
        array(
            'data'   => $data,
            'medias' => $medias,
        )
    );
})
    ->bind('choose-color');

$app->get('/choose-picto/', function (Silex\Application $app) {
    $dominantColors = $app['session']->get('dominant_colors');
    return $app['twig']->render(
        'choose-picto.html.twig',
        array(
            'dominantColors' => $dominantColors,
        )
    );
})
    ->bind('choose-picto');

$app->get('/print-it/{id}', function (Silex\Application $app, $id) {
    $dominantColors = $app['session']->get('dominant_colors');
    return $app['twig']->render(
        'print-it.html.twig',
        array(
            'colors' => $dominantColors[$id],
            'id' => $id,
        )
    );;
})
    ->bind('print-it');

$app->run();