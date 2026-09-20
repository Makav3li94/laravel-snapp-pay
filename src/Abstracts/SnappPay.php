<?php

namespace BackendProgramer\SnappPay\Abstracts;

use BackendProgramer\SnappPay\Contracts\SnappPayInterface;
use BackendProgramer\SnappPay\Order\Order;
use BackendProgramer\SnappPay\SnappPayEndpoint;
use BackendProgramer\SnappPay\SnappPaySetting;
use BackendProgramer\SnappPay\Traits\EndpointSettings;
use BackendProgramer\SnappPay\Traits\OrderSettings;
use JetBrains\PhpStorm\Pure;

abstract class SnappPay implements SnappPayInterface
{
    use EndpointSettings;
    use OrderSettings;

    /**
     * Base Setting.
     *
     * @var SnappPaySetting setting
     */
    protected SnappPaySetting $setting;

    /**
     * End point of urls.
     *
     * @var SnappPayEndpoint endPoint
     */
    protected SnappPayEndpoint $endPoint;

    /**
     * Expaierd Values.
     *
     * @var array expiredValue
     */
    protected static array $expiredValue = [];

    /**
     * Class constructor.
     */
    public function __construct(?SnappPaySetting $setting = null, ?SnappPayEndpoint $endPoint = null)
    {
        if (! $setting) {
            $this->setting = new SnappPaySetting(true);
        } else {
            $this->setting = $setting;
        }

        if (! $endPoint) {
            $this->endPoint = new SnappPayEndpoint(true);
        } else {
            $this->endPoint = $endPoint;
        }
    }

    /**
     * Gets SnappPay API URL base.
     */
    #[Pure]
    public function getApiBaseUrl(): string
    {
        return $this->urlSlashCheck($this->setting->getBaseUrl(), true).'/';
    }

    /**
     * Gets SnappPay API request basic token.
     */
    abstract public function getRequestBasicToken(): array;

    /**
     * Gets SnappPay API request bearer token.
     */
    abstract public function getRequestBearerToken(): array;

    /**
     * Check Merchant Eligibility.
     */
    abstract public function isMerchantEligible(int $amount, string $currency): array;

    /**
     * Get Payment token.
     */
    abstract public function getPaymentToken(Order $order, string $callBackUrl, string $transactionId): array;

    /**
     * Verify order.
     */
    abstract public function verifyOrder(string $paymentToken): array;

    /**
     * Settle order.
     */
    abstract public function settleOrder(string $paymentToken): array;

    /**
     * Revert order.
     */
    abstract public function revertOrder(string $paymentToken): array;

    /**
     * Get Payment Status.
     */
    abstract public function getPaymentStatusOrder(string $paymentToken): array;

    /**
     * Cancel order.
     */
    abstract public function cancelOrder(string $paymentToken): array;

    /**
     * Update order.
     */
    abstract public function updateOrder(Order $order): array;

    /**
     * Checks response for any error.
     */
    protected function processResponse(array|string $response, array $requestArgs = [], string $requestUrl = ''): array
    {
        if ($this->snappPayIsJson($response)) {
            $response = json_decode($response, true);
        }
        // Check if response has an error, and return it back if it is.
        if (isset($response['curlError'])) {
            $responseCode = 500;
            $responseBody = $response['curlError'];
        } elseif (isset($response['status'])) {
            $responseCode = $response['status'];
            $responseBody = $response['data'] ?? null;
        } else {
            if (isset($response['successful']) && ! $response['successful']) {
                $responseCode = $this->getResponseCode($response['errorData']['errorCode']);
                $responseBody = $response['errorData']['message'];
            } else {
                $responseCode = 200;
                $responseBody = json_encode($response);
            }
        }

        $is_json = $this->snappPayIsJson($responseBody);
        // Check the status code, if it's not between 200 and 299 then it's an error.
        // for "RBA: Access Denied" strings raises array error in json_decode. for this king of errors,
        // it's better to return arrya of error message.
        if (! $is_json) {
            return [
                'status' => 'error',
                'successful' => false,
                'statusCode' => $responseCode,
                'message' => $responseBody,
                'url' => $requestUrl,
                'args' => $requestArgs,
            ];
        }

        return json_decode($responseBody, true);

    }

    /**
     * Custom remote request wrapper.
     */
    protected function request(string $endpoint, string $method = 'GET', string $token = 'Basic', array $args = [], ?string $url = null): array
    {
        if (! $url) {
            $url = $this->getApiBaseUrl().$endpoint;
        }

        $headers = $token === 'Basic' ? $this->getRequestBasicToken() : $this->getRequestBearerToken();

        $request = ['method' => $method];

        if ($method == 'GET' && ! empty($args) && is_array($args)) {
            $url = $url.'?'.http_build_query($args);
        } else {
            $request['body'] = $token === 'Basic' ? http_build_query($args) : json_encode($args);
        }

        $headers['user-agent'] = 'SnappPay, '.$this->setting->getClientId();
        $request['headers'] = $headers;

        $response = $this->curlExecute($url, $request);

        // Helper برای decode رشته‌های JSON که دوبار encode شده‌اند
        $normalizeJson = function ($data) {
            if (is_string($data)) {
                $decoded = json_decode($data, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $data = $decoded;
                }
            }

            return $data;
        };

        // decode رشته‌های JSON
        $body = $normalizeJson($response['body'] ?? $response ?? null);

        // تبدیل رشته‌ها داخل آرایه به UTF-8
        $bodyUtf8 = function ($data) use (&$bodyUtf8) {
            if (is_array($data)) {
                foreach ($data as $k => $v) {
                    $data[$k] = $bodyUtf8($v);
                }

                return $data;
            } elseif (is_string($data)) {
                return mb_convert_encoding($data, 'UTF-8', 'auto');
            }

            return $data;
        };

        $body = $bodyUtf8($body);

        // لاگ نهایی
        file_put_contents(
            storage_path('logs/snappay_requests.log'),
            json_encode([
                'time' => now()->toDateTimeString(),
                'endpoint' => $endpoint,
                'method' => $method,
                'request' => $args,
                'response_status' => $response['http_code'] ?? $response['status'] ?? 'unknown',
                'response_body' => $body,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL.PHP_EOL,
            FILE_APPEND
        );

        return $this->processResponse($response, $request, $url);
    }

    /**
     * Execute request by curl.
     */
    protected function curlExecute(string $url, array $request): array|string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => $request['method'],
            CURLOPT_POSTFIELDS => $request['body'] ?? '',
            CURLOPT_HTTPHEADER => $request['headers'],
            CURLINFO_HEADER_OUT => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,

        ]);
        $response = curl_exec($ch);
        $curlInfo = curl_getinfo($ch);
        if (curl_errno($ch)) {
            $error_msg = curl_error($ch);
            // log error
            curl_close($ch);

            return ['curlError' => $error_msg];
        }

        // log success request & response

        curl_close($ch);

        return $response;
    }

    /**
     * Set expired value in array.
     */
    protected function setExpiredValue(string $name, string $value, int $ttl): void
    {
        self::$expiredValue[$name] = [$value, $ttl];
    }

    /**
     * Gets expired value from array or false if expired data.
     */
    protected function getExpiredValue(string $name): bool
    {
        if (isset(self::$expiredValue[$name])) {
            if (isset(self::$expiredValue[$name][0])) {
                $value = self::$expiredValue[$name][0];
            } else {
                return false;
            }
            if (isset(self::$expiredValue[$name][1])) {
                $ttl = self::$expiredValue[$name][1];
            } else {
                return false;
            }
            if (time() >= $ttl) {
                return $value;
            } else {
                unset(self::$expiredValue[$name]);
            }
        }

        return false;
    }

    /**
     * Check response error and return error code.
     */
    protected function getResponseCode(int $errorCode): int
    {
        return match ($errorCode) {
            1000 => 500,
            1003, 1013 => 401,
            1008 => 409,
            1011 => 400,
            default => 200,
        };
    }

    /**
     * Check response is json or not.
     */
    protected function snappPayIsJson(array|string $string): bool
    {
        return ! is_array($string) && is_array(json_decode($string, true)) && (json_last_error() == JSON_ERROR_NONE);
    }
}
