<?php
namespace Admin;

use Silex\Application;
use Silex\ControllerProviderInterface;

class TbbAdminRoutingController implements ControllerProviderInterface
{
    public function connect( Application $app)
    {
      $app->register(new \Wef\SessionProvider());

        // creates a new controller based on the default route
        $controllers = $app['controllers_factory'];

        $controllers->before(function (\Symfony\Component\HttpFoundation\Request $request, \Silex\Application $app) {
          $user = $app['session']->get('user');
          $path_info = $request->getPathInfo();
          if( !in_array($path_info, ['/tbb/login', '/tbb/oauth-callback']) && empty($user) ){
            return new \Symfony\Component\HttpFoundation\RedirectResponse('./login?redirect=' . urlencode($request->getRequestUri()));
          }
        }, Application::EARLY_EVENT);

        $controllers->get('/', __NAMESPACE__.'\TbbAdminController::login');
        $controllers->get('/login', __NAMESPACE__.'\TbbAdminController::login');
        $controllers->get('/authorize', __NAMESPACE__.'\TbbAdminController::authorize');

        $controllers->get('/oauth-callback', __NAMESPACE__.'\TbbAdminController::salesforceOAuthCallback');

        $controllers->get('/logout', function(\Symfony\Component\HttpFoundation\Request $request, \Silex\Application $app){
          $app['session']->set('user', NULL);
          return $app->redirect('http://www.weforum.org');
        });

        $controllers->get('/booking', __NAMESPACE__.'\TbbAdminController::booking');

        $controllers->get('/search-participants', __NAMESPACE__.'\TbbAdminController::searchParticipants');

        $controllers->get('/load-times-availability', __NAMESPACE__.'\TbbAdminController::loadTimesAvailability');

        $controllers->get('/default-duration', __NAMESPACE__.'\TbbAdminController::getDefaultDuration');

        $controllers->get('/load-rooms-availability', __NAMESPACE__.'\TbbAdminController::loadRoomsAvailability');

        $controllers->post('/book-new-bilateral', __NAMESPACE__.'\TbbAdminController::bookBilateral');

        $controllers->get('/retry', __NAMESPACE__.'\TbbAdminController::retry');
        $controllers->get('/deploy', __NAMESPACE__.'\TbbAdminController::deploy');

        return $controllers;
    }
}