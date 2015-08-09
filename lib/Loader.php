<?php

/*
 * The MIT License (MIT)
 *
 * Copyright (c) 2014 Spomky-Labs
 *
 * This software may be modified and distributed under the terms
 * of the MIT license.  See the LICENSE file for details.
 */

namespace SpomkyLabs\Jose;

use Base64Url\Base64Url;
use Jose\JSONSerializationModes;
use Jose\JWAInterface;
use Jose\JWKInterface;
use Jose\JWKSetInterface;
use Jose\JWSInterface;
use Jose\JWTInterface;
use Jose\LoaderInterface;
use Jose\Operation\ContentEncryptionInterface;
use Jose\Operation\DirectEncryptionInterface;
use Jose\Operation\KeyAgreementInterface;
use Jose\Operation\KeyAgreementWrappingInterface;
use Jose\Operation\KeyEncryptionInterface;
use Jose\Operation\SignatureInterface;

/**
 * Class able to load JWS or JWE.
 * JWS object can also be verified.
 */
abstract class Loader implements LoaderInterface
{
    use PayloadConverter;
    use KeyChecker;

    /**
     * @return \Jose\JWAManagerInterface
     */
    abstract protected function getJWAManager();

    /**
     * @return \Jose\Compression\CompressionManagerInterface
     */
    abstract protected function getCompressionManager();

    /**
     * {@inheritdoc}
     */
    public function load($input, JWKSetInterface $jwk_set = null)
    {
        $json = Converter::convert($input, JSONSerializationModes::JSON_SERIALIZATION, false);
        if (!is_null($json)) {
            if (array_key_exists('signatures', $json)) {
                return $this->loadSerializedJsonJWS($json);
            }
            if (array_key_exists('recipients', $json)) {
                return $this->loadSerializedJsonJWE($json, $jwk_set);
            }
        }
        throw new \InvalidArgumentException('Unable to load the input');
    }

    /**
     * {@inheritdoc}
     */
    public function verifySignature(JWSInterface $jws, JWKSetInterface $jwk_set = null)
    {
        $complete_header = array_merge($jws->getProtectedHeader(), $jws->getUnprotectedHeader());
        if (is_null($jwk_set)) {
            $jwk_set = $this->getKeysFromCompleteHeader($complete_header);
        }

        if (0 === count($jwk_set)) {
            return false;
        }
        foreach ($jwk_set->getKeys() as $jwk) {
            if (!$this->checkKeyUsage($jwk, 'verification')) {
                continue;
            }
            $algorithm = $this->getAlgorithm($complete_header, $jwk);
            if (true === $algorithm->verify($jwk, $jws->getInput(), $jws->getSignature())) {
                return true;
            }
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function verify(JWTInterface $jwt)
    {
        $methods = ['checkExpirationTime', 'checkNotBefore', 'checkIssuedAt', 'checkCritical'];
        foreach ($methods as $method) {
            $this->$method($jwt);
        }

        return true;
    }

    /**
     * @param \Jose\JWTInterface $jwt
     *
     * @throws \Exception
     */
    protected function checkExpirationTime(JWTInterface $jwt)
    {
        if (!is_null($jwt->getExpirationTime()) && time() > $jwt->getExpirationTime()) {
            throw new \Exception('The JWT has expired.');
        }
    }

    /**
     * @param \Jose\JWTInterface $jwt
     *
     * @throws \Exception
     */
    protected function checkNotBefore(JWTInterface $jwt)
    {
        if (!is_null($jwt->getNotBefore()) && time() < $jwt->getNotBefore()) {
            throw new \Exception('The JWT has expired.');
        }
    }

    /**
     * @param \Jose\JWTInterface $jwt
     *
     * @throws \Exception
     */
    protected function checkIssuedAt(JWTInterface $jwt)
    {
        if (!is_null($jwt->getIssuedAt()) && time() < $jwt->getIssuedAt()) {
            throw new \Exception('The JWT is issued in the futur.');
        }
    }

    /**
     * @param \Jose\JWTInterface $jwt
     *
     * @throws \Exception
     */
    protected function checkCritical(JWTInterface $jwt)
    {
        if (!is_null($jwt->getCritical())) {
            foreach ($jwt->getCritical() as $critical) {
                if (is_null($jwt->getHeaderValue($critical)) && is_null($jwt->getPayloadValue($critical))) {
                    throw new \Exception(sprintf("The claim/header '%s' is marked as critical but value is not set.", $critical));
                }
            }
        }
    }

    /**
     * @param $data
     *
     * @return array|\Jose\JWSInterface
     */
    protected function loadSerializedJsonJWS($data)
    {
        $encoded_payload = $data['payload'];
        $payload = Base64Url::decode($encoded_payload);

        $jws = [];
        foreach ($data['signatures'] as $signature) {
            if (array_key_exists('protected', $signature)) {
                $encoded_protected_header = $signature['protected'];
                $protected_header = json_decode(Base64Url::decode($encoded_protected_header), true);
            } else {
                $encoded_protected_header = null;
                $protected_header = [];
            }
            $unprotected_header = isset($signature['header']) ? $signature['header'] : [];

            $jws[] = $this->createJWS($encoded_protected_header.'.'.$encoded_payload, $protected_header, $unprotected_header, $payload, Base64Url::decode($signature['signature']));
        }

        return count($jws) > 1 ? $jws : current($jws);
    }

    /**
     * @param string $input
     * @param $protected_header
     * @param $unprotected_header
     * @param string $payload
     * @param string $signature
     *
     * @throws \Exception
     *
     * @return \Jose\JWSInterface
     */
    protected function createJWS($input, $protected_header, $unprotected_header, $payload, $signature)
    {
        $complete_header = array_merge($protected_header, $unprotected_header);
        $this->convertJWTContent($complete_header, $payload);
        $jws = $this->getJWTManager()->createJWS();
        $jws->setPayload($payload);
        $jws->setInput($input);
        $jws->setSignature($signature);
        if (!empty($protected_header)) {
            $jws->setProtectedHeader($protected_header);
        }
        if (!empty($unprotected_header)) {
            $jws->setUnprotectedHeader($unprotected_header);
        }

        return $jws;
    }

    /**
     * @param $protected_header
     * @param $unprotected_header
     *
     * @return array
     */
    protected function getCompleteHeader($protected_header, $unprotected_header)
    {
        $complete_header = [];
        if (!is_null($protected_header)) {
            $tmp = json_decode(Base64Url::decode($protected_header), true);
            if (!is_array($tmp)) {
                throw new \InvalidArgumentException('Invalid protected header');
            }
            $complete_header = array_merge($complete_header, $tmp);
        }
        if (is_array($unprotected_header)) {
            $complete_header = array_merge($complete_header, $unprotected_header);
        }

        return $complete_header;
    }

    /**
     * @param array              $header
     * @param \Jose\JWKInterface $key
     *
     * @return \Jose\Operation\SignatureInterface|null
     */
    protected function getAlgorithm(array $header, JWKInterface $key)
    {
        if (!array_key_exists('alg', $header)) {
            if (is_null($key->getAlgorithm())) {
                throw new \InvalidArgumentException("No 'alg' parameter set in the header or the key.");
            } else {
                $alg = $key->getAlgorithm();
            }
        } else {
            $alg = $header['alg'];
        }

        $algorithm = $this->getJWAManager()->getAlgorithm($alg);
        if (!$algorithm instanceof SignatureInterface) {
            throw new \RuntimeException("The algorithm '$alg' is not supported or does not implement SignatureInterface.");
        }

        return $algorithm;
    }

    /**
     * @param \Jose\JWAInterface                         $key_encryption_algorithm
     * @param \Jose\Operation\ContentEncryptionInterface $content_encryption_algorithm
     * @param \Jose\JWKInterface                         $key
     * @param string|null                                $encrypted_cek
     * @param array                                      $header
     *
     * @return string
     */
    public function getCEK(JWAInterface $key_encryption_algorithm, ContentEncryptionInterface $content_encryption_algorithm, JWKInterface $key, $encrypted_cek, array $header)
    {
        if ($key_encryption_algorithm instanceof DirectEncryptionInterface) {
            return $key_encryption_algorithm->getCEK($key, $header);
        } elseif ($key_encryption_algorithm instanceof KeyAgreementInterface) {
            return $key_encryption_algorithm->getAgreementKey($content_encryption_algorithm->getCEKSize(), $key, null, $header);
        } elseif ($key_encryption_algorithm instanceof KeyAgreementWrappingInterface) {
            return $key_encryption_algorithm->unwrapAgreementKey($key, $encrypted_cek, $content_encryption_algorithm->getCEKSize(), $header);
        } elseif ($key_encryption_algorithm instanceof KeyEncryptionInterface) {
            return $key_encryption_algorithm->decryptKey($key, $encrypted_cek, $header);
        }
    }

    /**
     * @param array                 $data
     * @param \Jose\JWKSetInterface $jwk_set
     *
     * @return \Jose\JWEInterface|null
     */
    protected function loadSerializedJsonJWE($data, JWKSetInterface $jwk_set = null)
    {
        $ciphertext = Base64Url::decode($data['ciphertext']);
        $protected = array_key_exists('protected', $data) ? json_decode(Base64Url::decode($data['protected']), true) : [];
        $unprotected = array_key_exists('unprotected', $data) ? $data['unprotected'] : [];
        $aad = array_key_exists('aad', $data) ? Base64Url::decode($data['aad']) : null;
        $tag = array_key_exists('tag', $data) ? Base64Url::decode($data['tag']) : null;
        $iv = array_key_exists('iv', $data) ? Base64Url::decode($data['iv']) : null;

        $result = [];
        foreach ($data['recipients'] as $recipient) {
            $header = array_key_exists('header', $recipient) ? $recipient['header'] : [];
            $complete_header = array_merge($protected, $unprotected, $header);

            $cek = $this->decryptCEK($recipient, $complete_header, $jwk_set);
            if (!is_null($cek)) {
                $result[] = $this->decryptPayload($ciphertext, $cek, $iv, $aad, $unprotected, $header, $protected, $data['protected'], $tag, $complete_header);
            }
        }

        return count($result) > 1 ? $result : current($result);
    }

    /**
     * @param string      $ciphertext
     * @param string      $cek
     * @param string|null $iv
     * @param string|null $aad
     * @param             $unprotected
     * @param             $header
     * @param             $protected
     * @param             $data_protected
     * @param string|null $tag
     * @param array       $complete_header
     *
     * @throws \Exception
     *
     * @return \Jose\JWEInterface
     */
    protected function decryptPayload($ciphertext, $cek, $iv, $aad, $unprotected, $header, $protected, $data_protected, $tag, array $complete_header)
    {
        $content_encryption_algorithm = $this->getContentEncryptionAlgorithm($complete_header['enc']);

        $payload = $content_encryption_algorithm->decryptContent($ciphertext, $cek, $iv, $aad, $data_protected, $tag);

        $this->uncompressedPayload($payload, $complete_header);

        $this->convertJWTContent($complete_header, $payload);

        $jwe = $this->getJWTManager()->createJWE();
        $jwe->setProtectedHeader($protected)
            ->setUnprotectedHeader(array_merge($unprotected, $header))
            ->setPayload($payload);

        return $jwe;
    }

    /**
     * @param array                 $recipient
     * @param array                 $complete_header
     * @param \Jose\JWKSetInterface $jwk_set
     *
     * @return string|null
     */
    protected function decryptCEK(array $recipient, array $complete_header, JWKSetInterface $jwk_set = null)
    {
        $encrypted_key = array_key_exists('encrypted_key', $recipient) ? Base64Url::decode($recipient['encrypted_key']) : null;

        $this->checkCompleteHeader($complete_header);
        $keys = $jwk_set;
        if (is_null($keys)) {
            $keys = $this->getKeysFromCompleteHeader($complete_header);
        }
        $key_encryption_algorithm = $this->getKeyEncryptionAlgorithm($complete_header['alg']);
        $content_encryption_algorithm = $this->getContentEncryptionAlgorithm($complete_header['enc']);

        foreach ($keys as $key) {
            if (!$this->checkKeyUsage($key, 'decryption')) {
                continue;
            }
            $cek = $this->getCEK($key_encryption_algorithm, $content_encryption_algorithm, $key, $encrypted_key, $complete_header);
            if (!is_null($cek)) {
                return $cek;
            }
        }
    }

    /**
     * @param array $complete_header
     *
     * @throws \InvalidArgumentException
     */
    protected function checkCompleteHeader(array $complete_header)
    {
        foreach (['enc', 'alg'] as $key) {
            if (!array_key_exists($key, $complete_header)) {
                throw new \InvalidArgumentException(sprintf("Parameters '%s' is missing.", $key));
            }
        }
    }

    /**
     * @param string $algorithm
     *
     * @return \Jose\Operation\DirectEncryptionInterface|\Jose\Operation\KeyEncryptionInterface|\Jose\Operation\KeyAgreementInterface|\Jose\Operation\KeyAgreementWrappingInterface
     */
    protected function getKeyEncryptionAlgorithm($algorithm)
    {
        $key_encryption_algorithm = $this->getJWAManager()->getAlgorithm($algorithm);
        foreach ([
                    '\Jose\Operation\DirectEncryptionInterface',
                    '\Jose\Operation\KeyEncryptionInterface',
                    '\Jose\Operation\KeyAgreementInterface',
                    '\Jose\Operation\KeyAgreementWrappingInterface',
                ] as $class) {
            if ($key_encryption_algorithm instanceof $class) {
                return $key_encryption_algorithm;
            }
        }
        throw new \RuntimeException(sprintf("The key encryption algorithm '%s' is not supported or not a key encryption algorithm instance.", $algorithm));
    }

    /**
     * @param $algorithm
     *
     * @return \Jose\Operation\ContentEncryptionInterface
     */
    protected function getContentEncryptionAlgorithm($algorithm)
    {
        $content_encryption_algorithm = $this->getJWAManager()->getAlgorithm($algorithm);
        if (!$content_encryption_algorithm instanceof ContentEncryptionInterface) {
            throw new \RuntimeException("The algorithm '".$algorithm."' does not implement ContentEncryptionInterface.");
        }

        return $content_encryption_algorithm;
    }

    /**
     * @param string $payload
     * @param array  $complete_header
     */
    protected function uncompressedPayload(&$payload, array $complete_header)
    {
        if (array_key_exists('zip', $complete_header)) {
            $compression_method = $this->getCompressionMethod($complete_header['zip']);
            $payload = $compression_method->uncompress($payload);
            if (!is_string($payload)) {
                throw new \RuntimeException('Decompression failed');
            }
        }
    }

    protected function getCompressionMethod($method)
    {
        $compression_method = $this->getCompressionManager()->getCompressionAlgorithm($method);
        if (is_null($compression_method)) {
            throw new \RuntimeException(sprintf("Compression method '%s' not supported"), $method);
        }

        return $compression_method;
    }

    protected function getKeysFromCompleteHeader(array $header)
    {
        $keys = $this->getJWKSetManager()->createJWKSet();
        if (!is_null($jwk = $this->getJWKManager()->findByHeader($header))) {
            $keys->addKey($jwk);
        }
        if (!is_null($jwkset = $this->getJWKSetManager()->findByHeader($header))) {
            foreach ($jwkset as $key) {
                $keys->addKey($key);
            }
        }

        return $keys;
    }
}
