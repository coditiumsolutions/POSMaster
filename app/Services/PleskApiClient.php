<?php

namespace App\Services;

use RuntimeException;

class PleskApiClient
{
    public function __construct(
        protected string $url,
        protected string $user,
        protected string $password,
    ) {
    }

    public function configured(): bool
    {
        return $this->url !== '' && $this->user !== '' && $this->password !== '';
    }

    /**
     * @return array{ok:bool,raw:string,xml:?\SimpleXMLElement,error:?string}
     */
    public function request(string $packetXml): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'raw' => '', 'xml' => null, 'error' => 'Plesk API credentials are not configured.'];
        }

        $endpoint = rtrim($this->url, '/') . '/enterprise/control/agent.php';
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $packetXml,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: text/xml',
                'HTTP_AUTH_LOGIN: ' . $this->user,
                'HTTP_AUTH_PASSWD: ' . $this->password,
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 120,
        ]);

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $raw === '') {
            return ['ok' => false, 'raw' => '', 'xml' => null, 'error' => $err ?: ('HTTP ' . $code)];
        }

        $xml = @simplexml_load_string($raw);
        if ($xml === false) {
            return ['ok' => false, 'raw' => $raw, 'xml' => null, 'error' => 'Invalid Plesk XML response.'];
        }

        return ['ok' => true, 'raw' => $raw, 'xml' => $xml, 'error' => null];
    }

    public function assertOk(\SimpleXMLElement $xml, string $context): void
    {
        foreach ($xml->xpath('//result') ?: [] as $result) {
            $status = (string) ($result->status ?? '');
            if ($status !== '' && strtolower($status) !== 'ok') {
                $text = trim((string) ($result->errtext ?? $result->errcode ?? 'Unknown Plesk error'));
                throw new RuntimeException($context . ': ' . $text);
            }
        }
    }
}
