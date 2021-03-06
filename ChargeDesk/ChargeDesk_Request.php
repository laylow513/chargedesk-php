<?php
/**
 * HTTP Request handler
 * Makes API Request to ChargeDesk
 */
class ChargeDesk_Request {
    const CONNECT_RETIRES = 3;

	/**
	 * Factory method, Make a HTTP request
	 * @param $method HTTP request method ("post", "get" or "delete")
	 * @param $path Relative request path
	 * @param $params Payload to include in request
	 * @param string $api_key API key to use for this request
	 * @return mixed Formatted object based on response from API
	 */
	public static function request($method, $path, $params, $api_key = null) {
		$request = new ChargeDesk_Request();
		return $request->requestRaw($method, $path, $params, $api_key);
	}

	/**
	 * Make a HTTP request
	 * @param $method HTTP request method ("post", "get" or "delete")
	 * @param $path Relative request path
	 * @param $params Payload to include in request
	 * @param string $api_key API key to use for this request
	 * @return mixed Formatted object based on response from API
	 */
	public function requestRaw($method, $path, $params, $api_key = null) {
		$url = ChargeDesk::$apiUrl."/v".ChargeDesk::$apiVersion."/$path";
		list($curlInfo, $curlResponse) = $this->_curlRequest($method, $url, $params, $api_key);
		return $this->_parseResponse($curlInfo, $curlResponse);
	}

	/**
	 * Parse response data from ChargeDesk API
	 * @param $curlInfo Payload from curl_info request containing information such as status codes
	 * @param $curlResponse Raw response data
	 * @return mixed Formatted object based on response from API
	 */
	private function _parseResponse($curlInfo, $curlResponse) {
		$status_code = intval($curlInfo['http_code']);
		$responseJSON = json_decode($curlResponse, false, 20);
		if($status_code < 200 || $status_code > 299 || $responseJSON === null || $responseJSON->error) {
			$this->_apiError($status_code, $curlResponse, $responseJSON);
		}
		return $responseJSON;
	}

	/**
	 * Perform final HTTP request
	 * @param $method HTTP request method ("post", "get" or "delete")
	 * @param $url Absolute URL to make request
	 * @param array $params Payload to send in request
	 * @param string $api_key API key to use for this request
	 * @return array $curlInfo, $curlResponse Containing data from response
	 */
	private function _curlRequest($method, $url, $params = array(), $api_key = null, $attempts = 0) {
		$curlOptions = array();
		$curlOptions[CURLOPT_URL] = $url;
		$curlOptions[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
		$curlOptions[CURLOPT_USERPWD] = ($api_key ? $api_key : ChargeDesk::$secretKey).":";
		$curlOptions[CURLOPT_RETURNTRANSFER] = true;
		$curlOptions[CURLOPT_CONNECTTIMEOUT] = 30;
		$curlOptions[CURLOPT_TIMEOUT] = 120;

		if(!ChargeDesk::$verifySSL) {
			$curlOptions[CURLOPT_SSL_VERIFYPEER] = false;
			$curlOptions[CURLOPT_SSL_VERIFYHOST] = false;
		}

		if($method == "post") {
			$curlOptions[CURLOPT_POST] = 1;
			$curlOptions[CURLOPT_POSTFIELDS] = $this->_encode($params);
		}
		else if($method == "get") {
			if($params) {
				$curlOptions[CURLOPT_URL] = $url."?".$this->_encode($params);
			}
		}

		$cdCurlHandle = curl_init();
		curl_setopt_array($cdCurlHandle, $curlOptions);
		$curlResponse = curl_exec($cdCurlHandle);
		$curlInfo = curl_getinfo($cdCurlHandle);

		$status_code = intval($curlInfo['http_code']);
		if(!$curlResponse || !$status_code) {
			$code = curl_errno($cdCurlHandle);
			$error = curl_error($cdCurlHandle);
			$error .= " (HTTP Status $status_code)";
			curl_close($cdCurlHandle);
			$cdCurlHandle = false;
            if(in_array($code, array(0, 7, 28, 52, 56), true) && ++$attempts < self::CONNECT_RETIRES) {
				sleep($attempts);
                return $this->_curlRequest($method, $url, $params, $api_key, $attempts);
            }
            else {
                if($attempts) {
                    $error = "[Failed after ".$attempts." attempts] ".$error;
                }
                $this->_curlError($code, $error);
            }
		}
		curl_close($cdCurlHandle);

		return array($curlInfo, $curlResponse);
	}

	/**
	 * Generate an error as a result of a curl failure
	 * @param $code Curl error code
	 * @param $error Curl Error message
	 * @throws ChargeDesk_ConnectError
	 */
	private function _curlError($code, $error) {
		throw new ChargeDesk_ConnectError($error, $code);
	}

	/**
	 * Generate an error as a result of a bad HTTP request
	 * @param $status_code HTTP status code from response
	 * @param $curlResponse Raw response data
	 * @param $responseJSON Formatted response object
	 * @throws ChargeDesk_RequestError
	 */
	private function _apiError($status_code, $curlResponse, $responseJSON) {
		$message = "There was an error talking to ChargeDesk";
		$incorrectParameter = false;
		if($responseJSON && $responseJSON->error) {
			if($responseJSON->error->message) {
				$message = $responseJSON->error->message;
			}
			if($responseJSON->error->incorrect_parameter) {
				$incorrectParameter = $responseJSON->error->incorrect_parameter;
			}
		}

		throw new ChargeDesk_RequestError($message, $status_code, $curlResponse, $responseJSON, $incorrectParameter);
	}

	/**
	 * Encode Payload parameters
	 * @param $arr Payload to encode
	 * @param string $prefix Name to prefix encoded parameters with
	 * @return string Encoded payload parameters
	 */
    public function _encode($arr, $prefix=null)
    {
        return http_build_query($arr);
    }
}
?>
