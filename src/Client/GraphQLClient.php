<?php

namespace Otago\SharedCmsSync\Client;

use RuntimeException;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;

/**
 * Posts a GraphQL query to another SilverStripe site and hands back the raw
 * response body.
 *
 * Raw, deliberately. What comes back is stored verbatim as a snapshot so a
 * failed mapping can be re-run against exactly what the far end said, rather
 * than against something this class decided to keep.
 */
class GraphQLClient
{
    use Injectable;
    use Configurable;

    /**
     * Seconds to wait for the far end. The default is generous because these
     * queries run from a queued job, not a page request, and a slow answer is
     * still better than a missing one.
     *
     * @config
     * @var int
     */
    private static $timeout = 60;

    /**
     * @config
     * @var int
     */
    private static $connect_timeout = 10;

    /**
     * @param string $url       endpoint to post to
     * @param string $query     GraphQL query document
     * @param array  $variables variables for the query
     * @return string the raw response body
     * @throws RuntimeException on transport failure, malformed JSON or GraphQL errors
     */
    public function query(string $url, string $query, array $variables = []): string
    {
        $payload = json_encode([
            'query' => $query,
            'variables' => $variables,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_POSTREDIR => CURL_REDIR_POST_ALL,
            CURLOPT_TIMEOUT => (int) $this->config()->get('timeout'),
            CURLOPT_CONNECTTIMEOUT => (int) $this->config()->get('connect_timeout'),
        ]);

        // Local and test environments run behind self-signed certificates.
        // Live never skips verification.
        if (!Director::isLive()) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        }

        $response = curl_exec($ch);

        if ($response === false) {
            $code = curl_errno($ch);
            $msg = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("cURL error ({$code}): {$msg}");
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        // A GraphQL endpoint behind a login answers 200 with an HTML login page,
        // which decodes as invalid JSON and reads like a broken endpoint. Say
        // what actually happened instead.
        if ($status >= 400) {
            throw new RuntimeException("HTTP {$status} from {$url}");
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException(
                "Invalid JSON response from {$url}: " . json_last_error_msg()
            );
        }

        if (isset($data['errors'])) {
            $msgs = array_map(
                fn ($e) => $e['message'] ?? '(no message)',
                $data['errors']
            );
            throw new RuntimeException('GraphQL error: ' . implode('; ', $msgs));
        }

        return $response;
    }
}
