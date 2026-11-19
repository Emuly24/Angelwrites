<?php
/**
 * TikTok Login Kit (v2) for Hybridauth, which has no TikTok provider of its own.
 * TikTok calls the client id "client_key" and shares no email address, so new members add it on
 * the short register step. Used by includes/social_auth.php when TIKTOK_CLIENT_KEY is set.
 */

use Hybridauth\Adapter\OAuth2;
use Hybridauth\Data;
use Hybridauth\User;
use Hybridauth\Exception\UnexpectedApiResponseException;

if (!class_exists('AngelWritesTikTok')) {
class AngelWritesTikTok extends OAuth2
{
    protected $scope = 'user.info.basic';
    protected $apiBaseUrl = 'https://open.tiktokapis.com/v2/';
    protected $authorizeUrl = 'https://www.tiktok.com/v2/auth/authorize/';
    protected $accessTokenUrl = 'https://open.tiktokapis.com/v2/oauth/token/';
    protected $apiDocumentation = 'https://developers.tiktok.com/doc/login-kit-web';

    protected function initialize()
    {
        parent::initialize();
        unset($this->AuthorizeUrlParameters['client_id'], $this->tokenExchangeParameters['client_id']);
        $this->AuthorizeUrlParameters['client_key'] = $this->clientId;
        $this->tokenExchangeParameters['client_key'] = $this->clientId;
        $this->tokenExchangeHeaders = ['Content-Type' => 'application/x-www-form-urlencoded'];
    }

    public function getUserProfile()
    {
        $response = $this->apiRequest('user/info/', 'GET', ['fields' => 'open_id,union_id,display_name,avatar_url']);
        $data = (new Data\Collection($response))->filter('data')->filter('user');
        if (!$data->exists('open_id')) {
            throw new UnexpectedApiResponseException('TikTok returned an unexpected response.');
        }
        $p = new User\Profile();
        $p->identifier = $data->get('open_id');
        $p->displayName = $data->get('display_name');
        $p->photoURL = $data->get('avatar_url');
        return $p;
    }
}
}
