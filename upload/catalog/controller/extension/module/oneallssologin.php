<?php

/**
 * @package      Oneall Single Sign-On
 * @copyright    Copyright 2017-Present http://www.oneall.com
 * @license      GNU/GPL 2 or later
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307,USA.
 *
 * The "GNU General Public License" (GPL) is available at
 * http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 *
 */
class ControllerExtensionModuleOneallSsoLogin extends \Oneall\AbstractOneallSsoController
{
    /**
     * Use to create oneall distant user if required
     *
     * We have to ckeck if the user is able to connect
     *
     * @return bool
     */
    public function preLogin()
    {
        if ( ! empty($_POST['email']) && ! empty($_POST['password']))
        {
            $this->storage->writePassword($_POST['password']);
            $this->storage->setLastAction(\Oneall\SessionStorage::ACTION_LOGIN);

            // Lookup the credentials in the cloud storage.
            $result = $this->api->lookUpByCredentials($_POST['email'], $_POST['password']);

            // Credentials match.
            if ($result->getStatusCode() == 200)
            {
                $response = new \Oneall\Phpsdk\Response\ResponseFacade(json_decode($result->getBody()));

                // Extract tokens from the received connection token.
                $userToken = $response->getUserToken();
                $identityToken = $response->getIdentityToken();

                // Pull profil (& create a customer if required)
                $customerId = $this->synchronizer->pull($identityToken, $userToken);
                if (!$customerId)
                {
                    $this->storage->allowConnection(false);
                    return null;
                }

                $sessionToken = $this->facade->getSsoSessionToken($identityToken);
                $this->addSsoLibrary($sessionToken);

                $this->login($userToken, $identityToken, $customerId);
                $this->storage->storeSessionToken($sessionToken);
            }
        }

        return null;
    }

    /**
     * User to connect the recently connected user.
     *
     * @param bool $force
     *
     * @return null
     */
    public function postLogin()
    {
        // if previous action is not "login", we skip
        if (!$this->storage->isLastAction(\Oneall\SessionStorage::ACTION_LOGIN))
        {
            return null;
        }

        // if a user is not logged in, we skip
        if (!$this->customer instanceof \Cart\Customer || !$this->customer->getId())
        {
            return null;
        }

        // reset previous action
        $this->storage->setLastAction(null);

        //  we recreate user(and link)
        $this->synchronizer->push($this->customer, $this->storage->consumePassword());

        $this->startSession($this->customer->getId());

        return null;
    }

    /**
     * On post logout, we'll remove our distant cookie.
     */
    public function preLogout()
    {
        // kill distant session
        $this->api->deleteSsoSession($this->storage->getSessionToken());

        // reset current session token
        $this->storage->resetSessionToken();
    }


}
