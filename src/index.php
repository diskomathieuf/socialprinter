<?php
require_once __DIR__ . '/../vendor/autoload.php';

date_default_timezone_set('Europe/Paris');

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Constraints as Assert;

$app = new Silex\Application();
if ($_SERVER['HTTP_HOST'] === 'socialprinter.localhost') $app['debug'] = true;

/* config.php contains instagram config and mail recepient
<?php
$app['insta'] = new MetzWeb\Instagram\Instagram(array(
'apiKey'      => '8fdaffffffffffffffffffff021c69f6',
'apiSecret'   => '87c023ffffffffffffffffffa880e595',
'apiCallback' => 'http://socialprinter.localhost/insta-image'
));
$app['printerMail'] = 'mail@host.com';
*/
require_once '../config.php';

$app->register(new Silex\Provider\SwiftmailerServiceProvider());
$app->register(new Silex\Provider\UrlGeneratorServiceProvider());
$app->register(new Silex\Provider\SessionServiceProvider());
$app->register(new Silex\Provider\FormServiceProvider());
$app->register(new Silex\Provider\TwigServiceProvider(), array(
    'twig.path' => __DIR__ . '/templates',
));
$app->register(new Silex\Provider\ValidatorServiceProvider());
$app->register(new Silex\Provider\TranslationServiceProvider(), array(
    'translator.domains' => array(),
));

$app['twig'] = $app->share($app->extend('twig', function ($twig, $app) {
    $twig->addFunction(new \Twig_SimpleFunction('asset', function ($asset) use ($app) {
        return sprintf('%s/%s', trim($app['request']->getBasePath()), ltrim($asset, '/'));
    }));
    return $twig;
}));

// ROUTES
$app->get('/', function (Silex\Application $app) {
    $app['session']->clear();
    $loginUrl = $app['insta']->getLoginUrl();

    return $app['twig']->render('index.twig',
        array(
            'loginUrl' => $loginUrl,
        )
    );
})
    ->bind('index');

$app->get('/insta-image', function (Silex\Application $app) {
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
        'insta-image.twig',
        array(
            'data'   => $data,
            'medias' => $medias,
        )
    );
})
    ->bind('insta-image');

$app->get('/choose-logo', function (Silex\Application $app) {
    $dominantColors = $app['session']->get('dominant_colors');
    return $app['twig']->render(
        'choose-logo.twig',
        array(
            'dominantColors' => $dominantColors,
        )
    );
})
    ->bind('choose-logo');

$app->match('/print-it/{id}', function (Request $request, $id) use ($app) {
    $dominantColors = $app['session']->get('dominant_colors');
    $sent = false;

    //Could use short array [] but have used long arrays in other parts
    $default = array(
        'name'    => '',
        'email'   => '',
        'message' => '',
    );

    $form = $app['form.factory']->createBuilder('form', $default)
        ->add('name', 'text', array(
            'constraints' => new Assert\NotBlank(array('message' => 'Ce champ est obligatoire')),
            'attr'        => array('class' => 'form-control', 'placeholder' => 'Votre prénom',),
            'label'       => 'Prénom'
        ))
        ->add('email', 'email', array(
            'constraints' => array(new Assert\Email(array('message' => 'Ceci n\'est pas un email valide')), new Assert\NotNull(array('message' => 'Ce champ est obligatoire'))),
            'attr'        => array('class' => 'form-control', 'placeholder' => 'Votre mail'),
            'label'       => 'Mail'
        ))
        ->add('picture', 'hidden', array())
        ->add('size', 'choice', array(
            'constraints' => array(new Assert\NotNull()),
            'attr'        => array('class' => 'form-control'),
            'choices'     => array('s' => 'S', 'm' => 'M', 'l' => 'L'),
            'expanded'    => true,
            'label'       => false
        ))
        ->add('validate', 'submit', array(
            'attr'  => array('class' => 'btn btn-default'),
            'label' => 'Imprimer'
        ))
        ->getForm();

    $form->handleRequest($request);

    if ($form->isValid()) {
        $data = $form->getData();
        $pictureInfo = array(
            time(),
            $data['name'],
            $data['size']
        );

        try {
            $png = empty($dominantColors) ? __DIR__ . '/images/logo-' . $id . '.png' : '/tmp/' . $id . '.png';
            $message_content = $app['twig']->render('email.template.twig');
            $message = Swift_Message::newInstance()
                ->setSubject('Christmas Party Homme – Galeries Lafayette Paris Haussmann')
                ->setFrom(array('no-reply@social-printer.fr' => 'Galeries Lafayette Paris Haussmann'))
                ->setTo(array($app['printerMail'], $data['email']))
                ->setBody($message_content, 'text/html')
                ->attach(Swift_Attachment::fromPath($png)->setFilename(join('_', $pictureInfo) . '.png')
                );

            $app['mailer']->send($message);
            $sent = true;
        } catch (Swift_TransportException $ste) {
            $sent = false;
        } catch (Exception $e) {
            $sent = false;
        }
    }
    $colors = isset($dominantColors[$id]) ? $dominantColors[$id] : null;

    if ($sent) {
        return $app['twig']->render(
            'validate.twig',
            array(
                'colors' => $colors,
                'id'     => $id,
            )
        );
    } else {
        return $app['twig']->render(
            'print-it.twig',
            array(
                'form'   => $form->createView(),
                'sent'   => $sent,
                'colors' => $colors,
                'id'     => $id,
            )
        );
    }
})
    ->bind('print-it');

$app->post('/save-picture', function (Silex\Application $app, Request $request) {
    $picture = $request->request->get('picture');
    if (!empty($picture)) {
        // Remove the headers (data:,) part.
        // A real application should use them according to needs such as to check image type
        $filteredData = substr($picture, strpos($picture, ",") + 1);

        // Need to decode before saving since the data we received is already base64 encoded
        $unencodedData = base64_decode($filteredData);

        //echo "unencodedData".$unencodedData;

        // Save file. This example uses a hard coded filename for testing,
        // but a real application can specify filename in POST variable
        $id = $request->request->get('id');
        $fp = fopen('/tmp/' . $id . '.png', 'w+');
        $result = fwrite($fp, $unencodedData);
        fclose($fp);
    }
    return $result;
})
    ->bind('save-picture');


$app->run();