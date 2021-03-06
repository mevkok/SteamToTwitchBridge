<?php

namespace App\Http\Controllers\Services;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use \Facebook\Facebook as FacebookApi;
use Illuminate\Support\Facades\Session;
use App\User;

class FacebookController extends Controller
{
    private $settings;
    private $user;
    private $service;
    private $slug;

    public function load(User $user = null) {
        $this->user = !!$user ? $user : Auth::user();
        $this->slug = 'service-facebook';
        $this->service = \App\Service::bySlug($this->slug);
        $this->settings = $this->service->getSettings();
        $this->settings['access'] = $this->service->getAccessSettings();
    }

    public function callback(Request $request) {
		if(!$userService = Auth::user()->hasService('service-facebook')) {
			$userService = new \App\UserService();
		}

		$data = $request->all();
		unset($data['_token']);

		$service = \App\Service::bySlug('service-facebook');
		$twitch = \App\Service::bySlug('service-twitch');

		$userService->user_id = Auth::user()->id;
		$userService->service_id = $service->id;
        $accessSettings = $twitch->getAccessSettings();
        $twitchLink = sprintf('https://www.twitch.tv/%s', $accessSettings['username']);
		$userService->settings = [
			'settings' => [
				'page' => -1,
				'message' => sprintf("Hi fans, I'm streaming at the moment.\nWatch my stream at %s", $twitchLink),
				'link' => $twitchLink
			],
			'access' => $data
		];

		$userService->save();

		return redirect()->route('users.profile')->with('success', 'Facebook profile linked!');
    }

    public function auth(Request $request)
    {
    	if(!Auth::user()->hasService('service-facebook')) {
    		return $this->callback($request);
		}

		return redirect()->route('users.profile')->with('info', 'Facebook already linked.');
    }

    public function save(Request $request) {
    	$this->load();
    	$page = $request->input('page');

    	if(!$service = Auth::user()->hasService($this->slug)) {
    		return [
    			'status' => 'Error',
    			'code' => 'danger',
    			'message' => 'Facebook has not been linked yet!'
    		];
    	}

    	$fbApi = new FacebookApi([]);
		$userService = $service->userService()->first();
		$settings = $userService->settings;
		$settings['settings']['page'] = $page;

    	$response = json_decode($fbApi->get(sprintf('/%s?fields=access_token', $page), $settings['access']['accessToken'])->getBody(), true);
    	$settings['settings']['page_access_token'] = $response['access_token'];

		$userService->settings = $settings;

		$userService->update();

    	return [
    		'status' => 'Success',
    		'code' => 'success',
    		'message' => 'Settings saved!'
    	];
    }

    public function destroy() {
    	$this->load();

    	if(!$service = Auth::user()->hasService($this->slug)) {
            Session::flash('error', "Facebook has not been linked yet!");
    		return [
    			'status' => 'Error',
    			'code' => 'danger',
    			'message' => 'Facebook has not been linked yet!'
    		];
    	}

    	$service->userService()->first()->delete();

        Session::flash('success', 'Facebook link - Removed!');
    	return [
    		'status' => 'Success',
    		'code' => 'success',
    		'message' => 'Facebook link - Removed!'
    	];
    }

    public function postMessage(User $user = null, $game = '', $redirect = true) {
    	$this->load($user);
        if(\App\LastStream::isUpdated($user, $game)) {
        	$userService = $this->service->userService($user)->first();
        	$fbApi = new FacebookApi([]);
        	$result = $fbApi->post(sprintf('/me/feed', $userService->settings['settings']['page']), [
        		'message' => sprintf("%s.\n\nPlaying %s", $userService->settings['settings']['message'], $game),
        		'link' => $userService->settings['settings']['link'],
    		], $userService->settings['settings']['page_access_token']);

            if($redirect) {
        		return redirect()->route('users.profile')->with('success', 'Post submitted to Facebook!');
            }
        }
    }
}
