<?php

/*
 * This file is part of the Behat WebApiExtension.
 * (c) Konstantin Kudryashov <ever.zet@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Behat\WebApiExtension\Context;

use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use DOMXPath;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use PHPUnit_Framework_Assert as Assertions;
use PHPUnit_Util_XML;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Provides web API description definitions.
 *
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 */
class WebApiContext implements ApiClientAwareContext
{
    /**
     * @var string
     */
    private $authorization;

    /**
     * @var ClientInterface
     */
    private $client;

    /**
     * @var array
     */
    private $headers = array();

    /**
     * @var bool
     */
    private $logAll = false;

    /**
     * @var \GuzzleHttp\Message\RequestInterface|RequestInterface
     */
    private $request;

    /**
     * @var \GuzzleHttp\Message\ResponseInterface|ResponseInterface
     */
    private $response;

    private $placeHolders = array();

    /**
     * {@inheritdoc}
     */
    public function setClient(ClientInterface $client)
    {
        $this->client = $client;

        if ($this->logAll) {
            $this->client->setDefaultOption('debug', true);
        }

        $this->setPlaceHolder('<base_url>', $client->getBaseUrl());
    }

    protected function logAll() {
        $this->logAll = true;
    }

    /**
     * Adds Basic Authentication header to next request.
     *
     * @param string $username
     * @param string $password
     *
     * @Given /^I am authenticating as "([^"]*)" with "([^"]*)" password$/
     */
    public function iAmAuthenticatingAs($username, $password)
    {
        $this->removeHeader('Authorization');
        $this->authorization = base64_encode($username . ':' . $password);
        $this->addHeader('Authorization', 'Basic ' . $this->authorization);
    }

    /**
     * Adds Bearer Token into Authentication header to next request.
     *
     * @param string $token
     *
     * @Given /^I am authenticating with "([^"]*)" token$/
     */
    public function iAmAuthenticatingWithToken($token)
    {
        $this->removeHeader('Authorization');
        $this->authorization = $token;
        $this->addHeader('Authorization', 'Bearer ' . $this->authorization);
    }

    /**
     * Sets a HTTP Header.
     *
     * @param string $name  header name
     * @param string $value header value
     *
     * @Given /^I set header "([^"]*)" with value "([^"]*)"$/
     */
    public function iSetHeaderWithValue($name, $value)
    {
        $this->addHeader($name, $value);
    }

    /**
     * Sends HTTP request to specific relative URL.
     *
     * @param string $method request method
     * @param string $url    relative url
     *
     * @When /^(?:I )?send a ([A-Z]+) request to "([^"]+)"$/
     */
    public function iSendARequest($method, $url)
    {
        $url = $this->prepareUrl($url);

        if (version_compare(ClientInterface::VERSION, '6.0', '>=')) {
            $this->request = new Request($method, $url, $this->headers);
        } else {
            $this->request = $this->getClient()->createRequest($method, $url);
            if (!empty($this->headers)) {
                $this->request->addHeaders($this->headers);
            }
        }

        $this->sendRequest();
    }

    /**
     * Sends HTTP request to specific URL with field values from Table.
     *
     * @param string    $method request method
     * @param string    $url    relative url
     * @param TableNode $post   table of post values
     *
     * @When /^(?:I )?send a ([A-Z]+) request to "([^"]+)" with values:$/
     */
    public function iSendARequestWithValues($method, $url, TableNode $post)
    {
        $url = $this->prepareUrl($url);
        $fields = array();

        foreach ($post->getRowsHash() as $key => $val) {
            $fields[$key] = $this->replacePlaceHolder($val);
        }

        $bodyOption = array(
          'body' => json_encode($fields),
        );

        if (version_compare(ClientInterface::VERSION, '6.0', '>=')) {
            $this->request = new Request($method, $url, $this->headers, $bodyOption['body']);
        } else {
            $this->request = $this->getClient()->createRequest($method, $url, $bodyOption);
            if (!empty($this->headers)) {
                $this->request->addHeaders($this->headers);
            }
        }

        $this->sendRequest();
    }

    /**
     * Sends HTTP request to specific URL with query params from Table.
     *
     * @param string    $method request method
     * @param string    $url    relative url
     * @param TableNode $queryParams   table of query params values
     *
     * @When /^(?:I )?send a ([A-Z]+) request to "([^"]+)" with query params:$/
     */
    public function iSendARequestWithQuery($method, $url, TableNode $queryParams)
    {
        $url = $this->prepareUrl($url);
        $params = array();

        foreach ($queryParams->getRowsHash() as $key => $val) {
            $params[$key] = $this->replacePlaceHolder($val);
        }

        // Compile url with query params
        $queryStr = '';
        foreach ($params as $key => $value) {
            if (empty($queryStr)) {
                $queryStr .= '?';
            } else {
                $queryStr .= '&';
            }

            $queryStr .= $key . '=' . $value;
        }

        $url .= $queryStr;

        if (version_compare(ClientInterface::VERSION, '6.0', '>=')) {
            $this->request = new Request($method, $url, $this->headers);
        } else {
            $this->request = $this->getClient()->createRequest($method, $url);
            if (!empty($this->headers)) {
                $this->request->addHeaders($this->headers);
            }
        }

        $this->sendRequest();
    }

    /**
     * Sends HTTP request to specific URL with raw body from PyString.
     *
     * @param string       $method request method
     * @param string       $url    relative url
     * @param PyStringNode $string request body
     *
     * @When /^(?:I )?send a ([A-Z]+) request to "([^"]+)" with body:$/
     */
    public function iSendARequestWithBody($method, $url, PyStringNode $string)
    {
        $url = $this->prepareUrl($url);
        $string = $this->replacePlaceHolder(trim($string));

        if (version_compare(ClientInterface::VERSION, '6.0', '>=')) {
            $this->request = new Request($method, $url, $this->headers, $string);
        } else {
            $this->request = $this->getClient()->createRequest(
                $method,
                $url,
                array(
                    'headers' => $this->getHeaders(),
                    'body' => $string,
                )
            );
        }

        $this->sendRequest();
    }

    /**
     * Sends HTTP request to specific URL with form data from PyString.
     *
     * @param string       $method request method
     * @param string       $url    relative url
     * @param PyStringNode $body   request body
     *
     * @When /^(?:I )?send a ([A-Z]+) request to "([^"]+)" with form data:$/
     */
    public function iSendARequestWithFormData($method, $url, PyStringNode $body)
    {
        $url = $this->prepareUrl($url);
        $body = $this->replacePlaceHolder(trim($body));

        $fields = array();
        parse_str(implode('&', explode("\n", $body)), $fields);

        if (version_compare(ClientInterface::VERSION, '6.0', '>=')) {
            $this->request = new Request($method, $url, ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query($fields, null, '&'));
        } else {
            $this->request = $this->getClient()->createRequest($method, $url);
            /** @var \GuzzleHttp\Post\PostBodyInterface $requestBody */
            $requestBody = $this->request->getBody();
            foreach ($fields as $key => $value) {
                $requestBody->setField($key, $value);
            }
        }

        $this->sendRequest();
    }


    /**
     * Checks that response has specific status code.
     *
     * @param string $code status code
     *
     * @Then /^(?:the )?response code should be (\d+)$/
     */
    public function theResponseCodeShouldBe($code)
    {
        $expected = intval($code);
        $actual = intval($this->response->getStatusCode());
        Assertions::assertSame($expected, $actual);
    }

    /**
     * Checks that response has specific media type.
     *
     * @param string $mediaType media type
     *
     * @Then /^(?:the )?response media type should be "([^"]*)"$/
     */
    public function theResponseMediaTypeShouldBe($mediaType)
    {
        $header = $this->response->getHeader('Content-Type');
        Assertions::assertContains($mediaType, $header);
    }

    /**
     * Checks that response has media type declared.
     *
     * @Then /^(?:the )?response media type should be known$/
     */
    public function theResponseMediaTypeShouldBeKnown()
    {
        $header = $this->response->getHeader('Content-Type');
//      TODO: Fix hard coded media type value
        Assertions::assertContains('application/xml', $header);
    }

    /**
     * Checks that response has specific charset.
     *
     * @param string $charset charset
     *
     * @Then /^(?:the )?response charset should be "([^"]*)"$/
     */
    public function theResponseCharsetShouldBe($charset)
    {
        $header = $this->response->getHeader('Content-Type');
        Assertions::assertContains($charset, $header);
    }

    /**
     * Checks that response has charset declared.
     *
     * @Then /^(?:the )?response charset should be known$/
     */
    public function theResponseCharsetShouldBeKnown()
    {
        $header = $this->response->getHeader('Content-Type');
//      TODO: Fix hard coded charset value
        Assertions::assertContains('UTF-8', $header);
    }

    /**
     * Checks that response has ETag header.
     *
     * @Then /^(?:the )?response etag should be not empty$/
     */
    public function theResponseEtagShouldNotBeEmpty()
    {
        $name = 'ETag';
        $header = $this->response->getHeader($name);
        Assertions::assertNotEmpty($header, "Header '$name' should not be empty");
    }

    /**
     * Checks that response has not ETag header.
     *
     * @Then /^(?:the )?response etag should be unknown$/
     */
    public function theResponseEtagShouldBeUnknown()
    {
        $name = 'ETag';
        $header = $this->response->getHeader($name);
        Assertions::assertEmpty($header, "Header '$name' should not be here, but it is '" . print_r($header, true) . "'");
    }


    /**
     * Checks that response has Date header.
     *
     * @Then /^(?:the )?response date should be not empty$/
     */
    public function theResponseDateShouldNotBeEmpty()
    {
        $header = $this->response->getHeader('Date');
        Assertions::assertNotEmpty($header);
    }

    /**
     * Checks that response has last modified header.
     *
     * @Then /^(?:the )?response last modified should not be empty$/
     */
    public function theResponseLastModifiedShouldNotBeEmpty()
    {
        $header = $this->response->getHeader('Last-Modified');
        Assertions::assertNotEmpty($header);
    }

    /**
     * Checks that response has not last modified header.
     *
     * @Then /^(?:the )?response last modified should be unknown$/
     */
    public function theResponseLastModifiedShouldBeUnknown()
    {
        $name = 'Last-Modified';
        $header = $this->response->getHeader($name);
        Assertions::assertEmpty($header, "Header '$name' should not be here, but it is '" . print_r($header, true) . "'");
    }

    /**
     * Checks that response has Cache-Control header.
     *
     * @Then /^(?:the )?response cache-control should be not empty$/
     */
    public function theResponseCacheControlShouldNotBeEmpty()
    {
        $header = $this->response->getHeader('Cache-Control');
        Assertions::assertNotEmpty($header, "Header 'Cache-Control' should not be empty");
    }

    /**
     * Checks that response is cacheable.
     *
     * @Then /^(?:the )?response should be cacheable$/
     */
    public function theResponseShouldBeCacheable()
    {
        $this->theResponseEtagShouldNotBeEmpty();
        $this->theResponseDateShouldNotBeEmpty();
        $this->theResponseLastModifiedShouldNotBeEmpty();
        $this->theResponseCacheControlShouldNotBeEmpty();
        $this->theResponseShouldContainHeader('Cache-Control', 'max-age=0, private, must-revalidate');
    }

    /**
     * Checks that response is not cacheable.
     *
     * @Then /^(?:the )?response should not be cacheable$/
     */
    public function theResponseShouldNotBeCacheable()
    {
        $this->theResponseEtagShouldBeUnknown();
        $this->theResponseLastModifiedShouldBeUnknown();
        $this->theResponseShouldContainHeader('Cache-Control', 'no-cache');
    }

    /**
     * Checks that response is well-formed.
     *
     * @Then /^(?:the )?response should be well-formed$/
     */
    public function theResponseShouldBeWellFormed()
    {
        $this->theResponseMediaTypeShouldBeKnown();
        $this->theResponseCharsetShouldBeKnown();
        $this->theResponseCacheControlShouldNotBeEmpty();
    }

    /**
     * Checks that response has specific header.
     *
     * @param string $name header name
     * @param string $value header value
     *
     * @Then /^(?:the )?response should contain header "([^"]*)" with value "([^"]*)"$/
     */
    public function theResponseShouldContainHeader($name, $value)
    {
        $expected = $value;
        $actual = $this->response->getHeader($name);
        Assertions::assertSame($expected, $actual);
    }

    /**
     * Checks that response has not empty header.
     *
     * @Then /^(?:the )?response header "([^"]*)" should not be empty$/
     */
    public function theResponseHeaderShouldNotBeEmpty($name)
    {
        $header = $this->response->getHeader($name);
        Assertions::assertNotEmpty($header, "Header '$name' should not be empty");
    }

    /**
     * Checks that response body contains specific text.
     *
     * @param string $text
     *
     * @Then /^(?:the )?response should contain "([^"]*)"$/
     */
    public function theResponseShouldContain($text)
    {
        $expectedRegexp = '/' . preg_quote($text) . '/i';
        $actual = (string) $this->response->getBody();
        Assertions::assertRegExp($expectedRegexp, $actual);
    }

    /**
     * Checks that response body doesn't contains specific text.
     *
     * @param string $text
     *
     * @Then /^(?:the )?response should not contain "([^"]*)"$/
     */
    public function theResponseShouldNotContain($text)
    {
        $expectedRegexp = '/' . preg_quote($text) . '/';
        $actual = (string) $this->response->getBody();
        Assertions::assertNotRegExp($expectedRegexp, $actual);
    }

    /**
     * Checks that response body contains JSON from PyString.
     *
     * Do not check that the response body /only/ contains the JSON from PyString,
     *
     * @param PyStringNode $jsonString
     *
     * @throws \RuntimeException
     *
     * @Then /^(?:the )?response should contain json:$/
     */
    public function theResponseShouldContainJson(PyStringNode $jsonString)
    {
        $etalon = json_decode($this->replacePlaceHolder($jsonString->getRaw()), true);
        $actual = json_decode($this->response->getBody(), true);

        if (null === $etalon) {
            throw new \RuntimeException(
              "Can not convert etalon to json:\n" . $this->replacePlaceHolder($jsonString->getRaw())
            );
        }

        if (null === $actual) {
            throw new \RuntimeException(
              "Can not convert actual to json:\n" . $this->replacePlaceHolder((string) $this->response->getBody())
            );
        }

        Assertions::assertGreaterThanOrEqual(count($etalon), count($actual));
        foreach ($etalon as $key => $needle) {
            Assertions::assertArrayHasKey($key, $actual);
            Assertions::assertEquals($etalon[$key], $actual[$key]);
        }
    }

    /**
     * Checks that response body contains XML from PyString.
     *
     * Do not check that the response body /only/ contains the XML from PyString,
     *
     * @param PyStringNode $xmlString
     *
     * @throws \RuntimeException
     *
     * @Then /^(?:the )?response should contain xml:$/
     */
    public function theResponseShouldContainXml(PyStringNode $xmlString)
    {
//        TODO: Make pretty decoding
        $expected = $this->replacePlaceHolder($xmlString->getRaw());
//        $etalon = static::xml_decode($this->replacePlaceHolder($xmlString->getRaw()), true);
        $actual = (string) $this->response->getBody();
//        $actual = static::xml_decode($this->response->getBody(), true);

        if (null === $expected) {
            throw new \RuntimeException(
                "Can not convert etalon to xml:\n" . $this->replacePlaceHolder($xmlString->getRaw())
            );
        }

        if (null === $actual) {
            throw new \RuntimeException(
                "Can not convert actual to xml:\n" . $this->replacePlaceHolder((string) $this->response->getBody())
            );
        }

//        Assertions::assertGreaterThanOrEqual(count($etalon), count($actual),
//        'Need ' . print_r($etalon, true) . ', but was ' . print_r($actual, true));

        Assertions::assertXmlStringEqualsXmlString($expected, $actual);

//        foreach ($etalon as $key => $needle) {
//            Assertions::assertArrayHasKey($key, $actual);
//            Assertions::assertEquals($etalon[$key], $actual[$key],
//                'Attempted to assert key ' . $key . ' in expected ' . print_r($etalon, true) . ' and actual ' . print_r($actual, true));
//        }

    }


    /**
     * Checks that response body contains XML elements in specified count.
     *
     * @param string $xpath
     * @param integer $count
     *
     * @throws \RuntimeException
     *
     * @Then /^(?:the )?response should contain xml elements "([^"]*)" in count (\d+)$/
     */
    public function theResponseShouldContainXmlElementsInCount($xpath, $count)
    {
        $expected = intval($count);
        $actual = (string) $this->response->getBody();

        if (null === $actual) {
            throw new \RuntimeException(
                "Can not convert actual to xml:\n" . $this->replacePlaceHolder((string) $this->response->getBody())
            );
        }

        $actual = PHPUnit_Util_XML::load($actual);
        $actual = new DOMXPath($actual);
        $actual = intval($actual->evaluate('count(' . $xpath . ')'));

        Assertions::assertEquals($expected, $actual);
    }

    /**
     * Checks that response body contains XML matching schema from PyString.
     *
     * @param PyStringNode $xsdString
     *
     * @throws \RuntimeException
     *
     * @Then /^(?:the )?response should contain xml matching schema:$/
     */
    public function theResponseShouldContainXmlMatchingSchema(PyStringNode $xsdString)
    {
        $schema = $this->replacePlaceHolder($xsdString->getRaw());
        $actualString = (string) $this->response->getBody();

        if (null === $schema) {
            throw new \RuntimeException(
                "Can not convert schema to xsd:\n" . $this->replacePlaceHolder($xsdString->getRaw())
            );
        }

        if (null === $actualString) {
            throw new \RuntimeException(
                "Can not convert actual to xml:\n" . $this->replacePlaceHolder((string) $this->response->getBody())
            );
        }

        $actual = PHPUnit_Util_XML::load($actualString);

        libxml_use_internal_errors(true);

        $valid = $actual->schemaValidateSource($schema);

        $message = '';
        if (!$valid) {

            $linedActualString = explode("\n", $actualString);

            $errors = libxml_get_errors();

            foreach ($errors as $error) {
                $message .= $this->display_xml_error($error, $linedActualString);
            }
        }

        Assertions::assertTrue($valid, $message);

        libxml_clear_errors();
    }

    function display_xml_error($error, $xml)
    {
        $return  = $xml[$error->line - 1] . "\n";
        $return .= str_repeat('-', $error->column) . "^\n";

        switch ($error->level) {
            case LIBXML_ERR_WARNING:
                $return .= "Warning $error->code: ";
                break;
            case LIBXML_ERR_ERROR:
                $return .= "Error $error->code: ";
                break;
            case LIBXML_ERR_FATAL:
                $return .= "Fatal Error $error->code: ";
                break;
        }

        $return .= trim($error->message) .
            "\n  Line: $error->line" .
            "\n  Column: $error->column";

//        if ($error->file) {
//            $return .= "\n  File: $error->file";
//        }

        return "$return\n\n--------------------------------------------\n\n";
    }


    /**
     * TODO: Write phpdoc
     * TODO: Sync functionality with json_decode()
     * TODO: Implement tests
     *
     * @param $xml
     * @param bool $assoc
     * @return mixed
     */
    protected static function xml_decode($xml, $assoc = false) {
        $parser = xml_parser_create();
        xml_parse_into_struct($parser, $xml, $vals, $index);
        xml_parser_free($parser);

        return $vals;
    }


    /**
     * Prints last response body.
     *
     * @Then print response
     */
    public function printResponse()
    {
        $request = $this->request;
        $response = $this->response;

        echo sprintf(
            "%s %s => %d:\n%s",
            $request->getMethod(),
            (string) ($request instanceof RequestInterface ? $request->getUri() : $request->getUrl()),
            $response->getStatusCode(),
            (string) $response->getBody()
        );
    }

    /**
     * Prepare URL by replacing placeholders and trimming slashes.
     *
     * @param string $url
     *
     * @return string
     */
    private function prepareUrl($url)
    {
        return ltrim($this->replacePlaceHolder($url), '/');
    }

    /**
     * Sets place holder for replacement.
     *
     * you can specify placeholders, which will
     * be replaced in URL, request or response body.
     *
     * @param string $key   token name
     * @param string $value replace value
     */
    public function setPlaceHolder($key, $value)
    {
        $this->placeHolders[$key] = $value;
    }

    /**
     * Replaces placeholders in provided text.
     *
     * @param string $string
     *
     * @return string
     */
    protected function replacePlaceHolder($string)
    {
        foreach ($this->placeHolders as $key => $val) {
            $string = str_replace($key, $val, $string);
        }

        return $string;
    }

    /**
     * Returns headers, that will be used to send requests.
     *
     * @return array
     */
    protected function getHeaders()
    {
        return $this->headers;
    }

    /**
     * Adds header
     *
     * @param string $name
     * @param string $value
     */
    protected function addHeader($name, $value)
    {
        if (isset($this->headers[$name])) {
            if (!is_array($this->headers[$name])) {
                $this->headers[$name] = array($this->headers[$name]);
            }

            $this->headers[$name][] = $value;
        } else {
            $this->headers[$name] = $value;
        }
    }

    /**
     * Removes a header identified by $headerName
     *
     * @param string $headerName
     */
    protected function removeHeader($headerName)
    {
        if (array_key_exists($headerName, $this->headers)) {
            unset($this->headers[$headerName]);
        }
    }

    private function sendRequest()
    {
        try {
            $this->response = $this->getClient()->send($this->request);
        } catch (RequestException $e) {
            $this->response = $e->getResponse();

            if (null === $this->response) {
                throw $e;
            }
        }
    }

    private function getClient()
    {
        if (null === $this->client) {
            throw new \RuntimeException('Client has not been set in WebApiContext');
        }

        return $this->client;
    }
}
