<?php

/*
 * The MIT License (MIT)
 *
 * Copyright (c) 2014 Spomky-Labs
 *
 * This software may be modified and distributed under the terms
 * of the MIT license.  See the LICENSE file for details.
 */

namespace SpomkyLabs\Jose\Algorithm\KeyEncryption;

use AESKW\A128KW as Wrapper;
use Base64Url\Base64Url;
use Jose\JWKInterface;

/**
 * Class A128KW.
 */
class A128KW extends AESKW
{
    /**
     * @return Wrapper
     */
    protected function getWrapper()
    {
        return new Wrapper();
    }

    /**
     * @param JWKInterface $key
     */
    protected function checkKey(JWKInterface $key)
    {
        parent::checkKey($key);
        if (16 !== strlen(Base64Url::decode($key->getValue('k')))) {
            throw new \InvalidArgumentException('The key size is not valid');
        }
    }

    /**
     * @return string
     */
    public function getAlgorithmName()
    {
        return 'A128KW';
    }
}
