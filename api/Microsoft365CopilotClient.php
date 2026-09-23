<?php
/*********************************************************************
 * Microsoft 365 Copilot Chat API client
 *
 * Uses a delegated Microsoft Graph access token.
 *********************************************************************/

class Microsoft365CopilotClient
{
    private $accessToken;
    private $graphBaseUrl;
    private $timeZone;

    public function __construct($accessToken, $timeZone = 'Europe/Oslo')
    {
        $this->accessToken = trim((string)$accessToken);
        $this->graphBaseUrl = 'https://graph.microsoft.com/beta';
        $this->timeZone = (string)$timeZone;

        if ($this->accessToken === '') {
            throw new InvalidArgumentException('Microsoft Graph access token is missing.');
        }
    }

    public function createConversation()
    {
        $response = $this->request(
            'POST',
            $this->graphBaseUrl . '/copilot/conversations',
            new stdClass()
        );

        if (empty($response['id'])) {
            throw new RuntimeException('Microsoft 365 Copilot returned no conversation ID.');
        }

        return (string)$response['id'];
    }

    public function sendMessage($conversationId, $message, $webSearchEnabled = true)
    {
        $conversationId = trim((string)$conversationId);
        $message = trim((string)$message);

        if ($conversationId === '' || $message === '') {
            throw new InvalidArgumentException('Conversation ID and message are required.');
        }

        $payload = array(
            'message' => array('text' => $message),
            'locationHint' => array('timeZone' => $this->timeZone),
        );

        if (!$webSearchEnabled) {
            $payload['contextualResources'] = array(
                'webContext' => array('isWebEnabled' => false),
            );
        }

        $response = $this->request(
            'POST',
            $this->graphBaseUrl . '/copilot/conversations/'
                . rawurlencode($conversationId) . '/chat',
            $payload
        );

        if (empty($response['messages']) || !is_array($response['messages'])) {
            throw new RuntimeException('Microsoft 365 Copilot returned no messages.');
        }

        for ($i = count($response['messages']) - 1; $i >= 0; --$i) {
            $item = $response['messages'][$i];
            if (!empty($item['text']) && ((string)$item['text'] !== $message)) {
                return (string)$item['text'];
            }
        }

        throw new RuntimeException('No Copilot response text was found.');
    }

    public function generateResponse($message, $webSearchEnabled = true)
    {
        $conversationId = $this->createConversation();
        return $this->sendMessage($conversationId, $message, $webSearchEnabled);
    }

    private function request($method, $url, $payload = null)
    {
        $headers = array(
            'Authorization: Bearer ' . $this->accessToken,
            'Accept: application/json',
            'Content-Type: application/json',
        );

        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 90,
        ));

        if ($payload !== null) {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                curl_close($ch);
                throw new RuntimeException('Unable to encode Microsoft Graph request.');
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }

        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Microsoft Graph cURL error: ' . $error);
        }

        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON from Microsoft Graph (HTTP ' . $status . ').');
        }

        if ($status < 200 || $status >= 300) {
            $message = isset($decoded['error']['message'])
                ? $decoded['error']['message'] : $body;
            throw new RuntimeException('Microsoft Graph error HTTP ' . $status . ': ' . $message);
        }

        return $decoded;
    }
}
