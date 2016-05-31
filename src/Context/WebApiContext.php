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
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use PHPUnit_Framework_Assert as Assertions;
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
        $header = $this->response->getHeader('ETag');
        Assertions::assertNotEmpty($header);
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
        $header = $this->response->getHeader('Last-Modified');
        Assertions::assertNull($header);
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
