<?php

define('HTTP_KEEP_ALIVE', 10);					// Keep-alive timeout in seconds
define('HTTP_MAX_REQUEST_SIZE', 2097152);		// Max http request size in bytes (headers + data)
define('HTTP_MAX_URI_LENGTH', 4096);			// Max length of the uri in the first http header
define('HTTP_MAX_CONN', 1024);					// Max number of simultaneous http connections
												// Experimentation showed it's best to keep this pretty high.
												// FD_SETSIZE is usually 1024; the max connections allowed on a socket.

class HttpClient
{
	public $socket			= null;
	public $ip				= '';
	public $port			= 0;
	public $localIP			= '';
	public $localPort		= 0;
	
	public $lastActivity	= 0;
	
	// send queue used for backlog, in case we can't send a reply in one go
	public $sendQ			= '';
	public $sendQLen		= 0;
	
	public $sendFile		= null;				// contains handle to file we're sending
	public $sendFilePntr	= 0;				// Points to where we are in the file
	public $sendFileSize	= 0;				// Points to where we are in the file

	private $sendWindow		= STREAM_READ_BYTES;	// dynamic window size

	private $httpRequest	= null;
	
	public function __construct($sock, $ip, $port)
	{
		$this->socket		= $sock;
		$this->ip			= $ip;
		$this->port			= $port;
		
		$localInfo = stream_socket_get_name($this->socket, false);
		$exp = explode(':', $localInfo);
		$this->localIP		= $exp[0];
		$this->localPort	= (int) $exp[1];
		
		$this->lastActivity	= time();
	}
	
	public function __destruct()
	{
		if (is_resource($this->socket))
			fclose($this->socket);
		
		if ($this->sendFile)
			$this->writeFileReset();
		if ($this->sendQLen > 0)
			$this->sendQReset();
	}
	
	public function write($data, $sendQPacket = FALSE)
	{
		$bytes = 0;
		$dataLen = strlen($data);
		if ($dataLen == 0)
			return 0;
		
		if (!is_resource($this->socket))
			return $bytes;
	
		if ($sendQPacket == TRUE)
		{
			// This packet came from the sendQ. We just try to send this and don't bother too much about error checking.
			// That's done from the sendQ flushing code.
			$bytes = @fwrite($this->socket, $data);
		}
		else
		{
			if ($this->sendQLen == 0)
			{
				// It's Ok to send packet
				$bytes = @fwrite($this->socket, $data);
				$this->lastActivity = time();
		
				if (!$bytes || $bytes != $dataLen)
				{
					// Could not send everything in one go - send the remainder to sendQ
					$this->addPacketToSendQ (substr($data, $bytes));
				}
			}
			else
			{
				// Remote is lagged
				$this->addPacketToSendQ($data);
			}
		}
	
		return $bytes;
	}
	
	public function addPacketToSendQ($data)
	{
		$this->sendQ			.= $data;
		$this->sendQLen			+= strlen($data);
	}
	
	public function flushSendQ()
	{
		// Send chunk of data
		$bytes = $this->write(substr($this->sendQ, 0, $this->sendWindow), TRUE);
		
		// Dynamic window sizing
		if ($bytes == $this->sendWindow)
			$this->sendWindow += STREAM_READ_BYTES;
		else
		{
			$this->sendWindow -= STREAM_READ_BYTES;
			if ($this->sendWindow < STREAM_READ_BYTES)
				$this->sendWindow = STREAM_READ_BYTES;
		}

		// Update the sendQ
		$this->sendQ = substr($this->sendQ, $bytes);
		$this->sendQLen -= $bytes;

		// Cleanup / reset timers
		if ($this->sendQLen == 0)
		{
			// All done flushing - reset queue variables
			$this->sendQReset();
		} 
		else if ($bytes > 0)
		{
			// Set when the last packet was flushed
			$this->lastActivity		= time();
		}
		//console('Bytes sent : '.$bytes.' - Bytes left : '.$this->sendQLen);
	}
	
	public function sendQReset()
	{
		$this->sendQ			= '';
		$this->sendQLen			= 0;
		$this->lastActivity		= time();
	}
	
	public function writeFile($fileName = '')
	{
		if ($fileName != '' && $this->sendFile == null)
		{
			$this->sendFile = fopen($fileName, 'rb');
			if (!$this->sendFile)
				return false;
			$this->sendFilePntr = 0;
			$this->sendFileSize = filesize($fileName);
		}
		
		$bytes = @fwrite($this->socket, fread($this->sendFile, $this->sendWindow));
		$this->sendFilePntr += $bytes;
		fseek($this->sendFile, $this->sendFilePntr);
		$this->lastActivity = time();
		
		// Dynamic window sizing
		if ($bytes == $this->sendWindow)
			$this->sendWindow += STREAM_READ_BYTES;
		else
		{
			$this->sendWindow -= STREAM_READ_BYTES;
			if ($this->sendWindow < STREAM_READ_BYTES)
				$this->sendWindow = STREAM_READ_BYTES;
		}
		
		//console('BYTES : '.$bytes.' - PNTR : '.$this->sendFilePntr);
		// Done?
		if ($this->sendFilePntr >= $this->sendFileSize)
			$this->writeFileReset();
	}
	
	private function writeFileReset()
	{
		@fclose($this->sendFile);
		$this->sendFile = null;
		$this->sendFileSize = 0;
		$this->sendFilePntr = 0;
	}

	private function createErrorPage($errNo, $errStr = '')
	{
		$eol = "\r\n";
		$out = '<html>'.$eol;
		$out .= '<head><title>'.$errNo.' '.HttpResponse::$responseCodes[$errNo].'</title></head>'.$eol;
		$out .= '<body bgcolor="white">'.$eol;
		$out .= '<center><h1>'.$errNo.' '.(($errStr != '') ? $errStr : HttpResponse::$responseCodes[$errNo]).'</h1></center>'.$eol;
		$out .= '<hr><center>PRISM v'.PHPInSimMod::VERSION.'</center>'.$eol;
		$out .= '</body>'.$eol;
		$out .= '</html>'.$eol;
		return $out;
	}
	
	public function read()
	{
		$this->lastActivity	= time();
		return fread($this->socket, STREAM_READ_BYTES);
	}
	
	public function handleInput(&$data, &$httpRequest, &$errNo)
	{
		// What is this? we're getting input while we're sending a reply?
		if ($this->sendFile)
		{
			$this->writeFileReset();
			$this->httpRequest = null;
		}
		else if ($this->sendQLen > 0)
		{
			$this->sendQReset();
			$this->httpRequest = null;
		}

		if (!$this->httpRequest)
			$this->httpRequest = new HttpRequest();

		// Pass the incoming data to the HttpRequest class, so it can handle it.		
		if (!$this->httpRequest->handleInput($data))
		{
			// An error was encountered while receiving the requst.
			// Send reply and return false to close this connection.
			$r = new HttpResponse('1.1', $this->httpRequest->errNo);
			$r->addBody($this->createErrorPage($this->httpRequest->errNo, $this->httpRequest->errStr));
			$this->write($r->getHeaders());
			$this->write($r->getBody());
			$errNo = $this->httpRequest->errNo;
			$this->httpRequest = null;
			return false;
		}
		
		// If we have no headers, or we are busy with receiving or sending.
		// Just return and wait for more data.
		if (!$this->httpRequest->hasHeaders || 			// We're still receiving headers
			$this->httpRequest->isReceiving) 			// We're still receiving the body of a request
			return true;								// Return true to just wait and try again later
		
		// At this point we have a fully qualified and parsed HttpRequest
		// The HttpRequest object contains all info about the headers / GET / POST / COOKIE
		// Just finalise it by adding some extra client info.
		$this->httpRequest->SERVER['REMOTE_ADDR']			= $this->ip;
		$this->httpRequest->SERVER['REMOTE_PORT']			= $this->port;
		$this->httpRequest->SERVER['SERVER_ADDR']			= $this->localIP;
		$this->httpRequest->SERVER['SERVER_PORT']			= $this->localPort;
		$exp = explode(':', $this->httpRequest->headers['Host']);
		$this->httpRequest->SERVER['SERVER_NAME']			= $exp[0];
		$this->httpRequest->SERVER['HTTP_HOST']				= $this->httpRequest->headers['Host'];
		$this->httpRequest->SERVER['HTTP_USER_AGENT']		= isset($this->httpRequest->headers['User-Agent']) ? $this->httpRequest->headers['User-Agent'] : '';
		$this->httpRequest->SERVER['HTTP_ACCEPT']			= isset($this->httpRequest->headers['Accept']) ? $this->httpRequest->headers['Accept'] : '';
		$this->httpRequest->SERVER['HTTP_ACCEPT_LANGUAGE']	= isset($this->httpRequest->headers['Accept-Language']) ? $this->httpRequest->headers['Accept-Language'] : '';
		$this->httpRequest->SERVER['HTTP_ACCEPT_ENCODING']	= isset($this->httpRequest->headers['Accept-Encoding']) ? $this->httpRequest->headers['Accept-Encoding'] : '';
		$this->httpRequest->SERVER['HTTP_ACCEPT_CHARSET']	= isset($this->httpRequest->headers['Accept-Charset']) ? $this->httpRequest->headers['Accept-Charset'] : '';
		$this->httpRequest->SERVER['HTTP_KEEP_ALIVE']		= isset($this->httpRequest->headers['Keep-Alive']) ? $this->httpRequest->headers['Keep-Alive'] : '';
		$this->httpRequest->SERVER['HTTP_REFERER']			= isset($this->httpRequest->headers['Referer']) ? $this->httpRequest->headers['Referer'] : '';
		
		//var_dump($this->httpRequest->headers);
		//var_dump($this->httpRequest->SERVER);
		//var_dump($this->httpRequest->GET);
		//var_dump($this->httpRequest->POST);
		//var_dump($this->httpRequest->COOKIE);
		
		// Should we serve a file or pass the request to PHPInSimMod for page generation?
		if ($this->httpRequest->SERVER['SCRIPT_NAME'] == '/')
		{
			// Serve internally generated webpage
			// Set httpRequest which will be passed back to the caller.
			$httpRequest = $this->httpRequest;
			
			// Build TEST response for now
			$html = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">';
			$html .= '<html xmlns="http://www.w3.org/1999/xhtml" dir="ltr" lang="en">';
			$html .= '<head>';
			$html .= '<title>Prism http server test page</title>';
			$html .= '</head>';
			$html .= '<body>';
	
			if (count($this->httpRequest->COOKIE) > 0)
			{
				$html .= 'The following COOKIE values have been found :<br />';
				foreach ($this->httpRequest->COOKIE as $k => $v)
					$html .= htmlspecialchars($k).' => '.htmlspecialchars($v).'<br />';
				$html .= '<br />';
			}
			
			if (count($this->httpRequest->GET) > 0)
			{
				$html .= 'You submitted the following GET values :<br />';
				foreach ($this->httpRequest->GET as $k => $v)
					$html .= htmlspecialchars($k).' => '.htmlspecialchars($v).'<br />';
				$html .= '<br />';
			}
			
			if (count($this->httpRequest->POST) > 0)
			{
				$html .= 'You submitted the following POST values :<br />';
				foreach ($this->httpRequest->POST as $k => $v)
				{
					if (is_array($v))
					{
						$html .= '<strong>'.$k.'-array</strong><br />';
						foreach ($v as $k2 => $v2)
							$html .= '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;'.$k.'['.htmlspecialchars($k2).'] => '.htmlspecialchars($v2).'<br />';
					}
					else
					{
						$html .= htmlspecialchars($k).' => '.htmlspecialchars($v).'<br />';
					}
				}
				$html .= '<br />';
			}
			
			$html .= 'Here\'s a form to test POST requests<br />';
			$html .= '<form method="post" action="/?'.$this->httpRequest->SERVER['QUERY_STRING'].'">';
			$html .= '';
			for ($c=0; $c<3; $c++)
				$html .= 'name="postval'.$c.'" : <input type="text" name="postval'.$c.'" value="'.htmlspecialchars($this->createRandomString(24)).'" maxlength="48" size="32" /><br />';
			for ($c=0; $c<3; $c++)
				$html .= 'name="postval[blah'.$c.']" : <input type="text" name="postval[blah'.$c.']" value="'.htmlspecialchars($this->createRandomString(24)).'" maxlength="48" size="32" /><br />';
			for ($c=0; $c<3; $c++)
				$html .= 'name="postval[]" : <input type="text" name="postval[]" value="'.htmlspecialchars($this->createRandomString(24)).'" maxlength="48" size="32" /><br />';
			$html .= 'name="postvalother" : <input type="text" name="postvalother" value="" maxlength="48" size="32" /><br />';
			$html .= '<input type="submit" value="Submit the form" />';
			$html .= '</form>';
			
			for ($x=0; $x<100; $x++)
			{
				$html .= '<br /><br />SERVER values :<br />';
				foreach ($this->httpRequest->SERVER as $k => $v)
					$html .= htmlspecialchars($k).' => '.htmlspecialchars($v).'<br />';
			}
			$html .= '</body>';
			$html .= '</html>';

			$r = new HttpResponse($this->httpRequest->SERVER['httpVersion'], 200);
			$r->addBody($html);
			$r->addHeader('Content-Type: text/html');
			$r->setCookie('testCookie', 'a test value in this cookie', time() + 60*60*24*7, '/', 'vic.lfs.net');
			$r->setCookie('anotherCookie', '#@$%"!$:;%@{}P$%', time() + 60*60*24*7, '/', $this->httpRequest->SERVER['SERVER_NAME']);

			$this->write($r->getHeaders());
			$this->write($r->getBody());
		}
		else if (file_exists(ROOTPATH.'/www-docs'.$this->httpRequest->SERVER['SCRIPT_NAME']))
		{
			// Serve file - we can do this using the writeFile() method, which is memory friendly
			$r = new HttpResponse($this->httpRequest->SERVER['httpVersion'], 200);
			$r->addHeader('Content-Type: '.$this->getMimeType());
			$r->addHeader('Content-Length: '.filesize(ROOTPATH.'/www-docs'.$this->httpRequest->SERVER['SCRIPT_NAME']));

			$this->write($r->getHeaders());
			$this->writeFile(ROOTPATH.'/www-docs'.$this->httpRequest->SERVER['SCRIPT_NAME']);
		}
		else
		{
			// 404
			$r = new HttpResponse($this->httpRequest->SERVER['httpVersion'], 404);
			$r->addBody($this->createErrorPage(404));

			$this->write($r->getHeaders());
			$this->write($r->getBody());
		}
		
		// log line
		$logLine =
			$this->ip.' - - ['.date('d/M/Y:H:i:s O').'] '.
			'"'.$this->httpRequest->SERVER['REQUEST_METHOD'].' '.$this->httpRequest->SERVER['REQUEST_URI'].' '.$this->httpRequest->SERVER['SERVER_PROTOCOL'].'" '.
			$r->getResponseCode().' '.
			$r->getHeader('Content-Length').' '.
			'"'.$this->httpRequest->SERVER['HTTP_REFERER'].'" '.
			'"'.$this->httpRequest->SERVER['HTTP_USER_AGENT'].'" '.
			'"-"';
		console($logLine);
		file_put_contents(ROOTPATH.'/logs/http.log', $logLine."\r\n", FILE_APPEND);
		
		// Reset httpRequest
//		if ($this->httpRequest->rawInput != '')
//		{
//			$rawInput = $this->httpRequest->rawInput;
//			$this->httpRequest = null;
//			return $this->handleInput($rawInput);
//		}
//		else
//		{
			$this->httpRequest = null;
//		}

		return true;
	}
	
	private function getMimeType()
	{
		$pathInfo = pathinfo($this->httpRequest->SERVER['SCRIPT_NAME']);
		
		$mimeType = 'application/octet-stream';
		switch(strtolower($pathInfo['extension']))
		{
			case 'txt' :
				$mimeType = 'text/plain';
				break;

			case 'html' :
			case 'htm' :
			case 'shtml' :
				$mimeType = 'text/html';
				break;

			case 'css' :
				$mimeType = 'text/css';
				break;

			case 'xml' :
				$mimeType = 'text/xml';
				break;

			case 'gif' :
				$mimeType = 'image/gif';
				break;

			case 'jpeg' :
			case 'jpg' :
				$mimeType = 'image/jpeg';
				break;

			case 'png' :
				$mimeType = 'image/png';
				break;

			case 'tif' :
			case 'tiff' :
				$mimeType = 'image/tiff';
				break;

			case 'wbmp' :
				$mimeType = 'image/vnd.wap.wbmp';
				break;

			case 'bmp' :
				$mimeType = 'image/x-ms-bmp';
				break;

			case 'svg' :
				$mimeType = 'image/svg+xml';
				break;

			case 'ico' :
				$mimeType = 'image/x-icon';
				break;

			case 'js' :
				$mimeType = 'application/x-javascript';
				break;

			case 'atom' :
				$mimeType = 'application/atom+xml';
				break;

			case 'rss' :
				$mimeType = 'application/rss+xml';
				break;
		}
		return $mimeType;
	}
	
	private function createRandomString($len)
	{
		$out = '';
		for ($a=0; $a<$len; $a++)
			$out .= chr(rand(32, 127));
		return $out;
	}
}

class HttpRequest
{
	public $rawInput		= '';
	
	public $isReceiving		= false;
	public $hasRequestUri	= false;
	public $hasHeaders		= false;

	public $errNo			= 0;
	public $errStr			= '';
	
	public $headers			= array();		// This will hold all of the request headers from the clients browser.

	public $SERVER			= array();
	public $GET				= array();		// With these arrays we try to recreate php's global vars a bit.
	public $POST			= array();
	public $COOKIE			= array();
	
	public function __construct()
	{

	}
	
	public function handleInput(&$data)
	{
		// We need to buffer the input - no idea how much data will 
		// be coming in until we have received all the headers.
		// Normally though all headers should come in unfragmented, but don't rely on that.
		$this->rawInput .= $data;
		if (strlen($this->rawInput) > HTTP_MAX_REQUEST_SIZE)
		{
			$this->errNo = 413;
			$this->errStr = 'You tried to send more than '.HTTP_MAX_REQUEST_SIZE.' bytes to the server, which it doesn\'t like.';
			return false;
		}

		// Check if we have header lines in the buffer, for as long as !$this->hasHeaders
		if (!$this->hasHeaders)
		{
			if (!$this->parseHeaders())
				return false;				// returns false is something went wrong (bad headers)
		}
		
		// If we have headers then we can now figure out if we have received all there is,
		// or if there is more to come. If there is, just return true and wait for more.
		if ($this->hasHeaders)
		{
			// With a GET there will be no extra data
			if ($this->SERVER['REQUEST_METHOD'] == 'POST')
			{
				// Check if we have enough and proper data to read the POST
				if (!isset($this->headers['Content-Length']))
				{
					$this->errNo = 411;
					return false;
				}
				if (!isset($this->headers['Content-Type']) || $this->headers['Content-Type'] != 'application/x-www-form-urlencoded')
				{
					$this->errNo = 415;
					$this->errStr = 'No Content-Type was provided that I can handle. At the moment I only like application/x-www-form-urlencoded.';
					return false;
				}
				
				// Should we expect more data to come in?
				if ((int) $this->headers['Content-Length'] > strlen($this->rawInput))
				{
					// We have not yet received all the POST data, so I'll return and wait.
					$this->isReceiving = true;
					return true;
				}
				
				// At this point we have the whole POST body
				$this->isReceiving = false;
				
				// Parse POST variables
				$this->parsePOST(substr($this->rawInput, 0, $this->headers['Content-Length']));
				
				// Cleanup rawInput
				$this->rawInput = substr($this->rawInput, $this->headers['Content-Length']);
			}
			else
			{
				$this->isReceiving = false;
				
			}
			
			// At this point we have received the entire request. So finally let's parse the (remaining) user variables.
			// Parse GET variables
			$this->parseGET();

			// Parse cookie values
			$this->parseCOOKIE();
			
			// At this point we have parsed the entire request. We are done.
			// Because isReceiving is now false, the HttpClient::handleInput function will 
			// pass the values of this object to the html generation / admin class.
		}
		
		return true;
	}
	
	private function parseHeaders()
	{
		// Loop through each individual header line
		do
		{
			// Do we have a header line?
			$pos = strpos($this->rawInput, "\r\n");
			if ($pos === false)
			{
				// Extra (garbage) input error checking here
				if (!$this->hasRequestUri)
				{
					$len = strlen($this->rawInput);
					if ($len > HTTP_MAX_URI_LENGTH)
					{
						$this->errNo = 414;
						return false;
					}
					else if ($len > 3 && !preg_match('/^(GET|POST).*$/', $this->rawInput))
					{
						$this->errNo = 400;
						return false;
					}
				}
				
				// Otherwise just return and wait for more data
				return true;
			}
			else if ($pos === 0)
			{
				// This cannot possibly be the end of headers, if we don't even have a request uri (or host header)
				if (!$this->hasRequestUri || !isset($this->headers['Host']))
				{
					$this->errNo = 400;
					return false;
				}
				
				// This should be end of headers
				$this->hasHeaders = true;
				$this->rawInput = substr($this->rawInput, 2);		// remove second \r\n
				return true;
			}
			
			$header = substr($this->rawInput, 0, $pos);
			$this->rawInput = substr($this->rawInput, $pos+2);		// +2 to include \r\n

			// Do we have a request line already? If not, try to parse this header line as a request line
			if (!$this->hasRequestUri)
			{
				// Read the first header (the request line)
				if (!$this->parseRequestLine($header))
				{
					$this->errNo = 400;
					return false;
				}
				$this->hasRequestUri = true;
			}
			else if (!$this->hasHeaders)
			{
				if (strpos($header, ':') === false)
				{
					$this->errNo = 400;
					return false;
				}
				
				// Parse regular header line
				$exp = explode(':', $header, 2);
				if (count($exp) == 2)
					$this->headers[trim($exp[0])] = trim($exp[1]);
			}
		} while (true);
		return true;
	}
	
	private function parseRequestLine($line)
	{
		$exp = explode(' ', $line);
		if (count($exp) != 3)
			return false;
		
		// check the request command
		if ($exp[0] != 'GET' && $exp[0] != 'POST')
			return false;
		$this->SERVER['REQUEST_METHOD'] = $exp[0];
		
		// Check the request uri
		$this->SERVER['REQUEST_URI'] = $exp[1];
		if (($uri = parse_url($this->SERVER['REQUEST_URI'])) === false)
			return false;
			
		// Path sanitation
		$uri['path'] = filter_var(trim($uri['path']), FILTER_UNSAFE_RAW, FILTER_FLAG_STRIP_LOW);
		if (!isset($uri['path'][0]) || $uri['path'][0] != '/')
			return false;
		$this->SERVER['SCRIPT_NAME'] = $uri['path'];
		
		// Set the query string - all chars allowed in there
		$this->SERVER['QUERY_STRING'] = isset($uri['query']) ? $uri['query'] : '';
		
		// Check for user trying to go below webroot
		$exp2 = explode('/', $this->SERVER['SCRIPT_NAME']);
		foreach ($exp2 as $v)
		{
			if (trim($v) == '..')
			{
				// Ooops the user probably tried something nasty (reach a file outside of our www folder)
				return false;
			}
		}
		
		// Check the HTTP protocol version
		$this->SERVER['SERVER_PROTOCOL'] = $exp[2];
		$httpexp = explode('/', $exp[2]);
		if ($httpexp[0] != 'HTTP' || ($httpexp[1] != '1.0' && $httpexp[1] != '1.1'))
			return false;
		$this->SERVER['httpVersion'] = $httpexp[1];

		return true;
	}
	
	private function parseGET()
	{
		$exp = explode('&', $this->SERVER['QUERY_STRING']);
		foreach ($exp as $v)
		{
			if ($v == '')
				continue;
			$exp2 = explode('=', $v, 2);
			$this->GET[urldecode($exp2[0])] = isset($exp2[1]) ? urldecode($exp2[1]) : '';
		}
	}
	
	private function parsePOST($raw)
	{
		$exp = explode('&', $raw);
		foreach ($exp as $v)
		{
			$exp2 = explode('=', $v);
			$key = urldecode($exp2[0]);
			$value = urldecode($exp2[1]);
			
			if (preg_match('/^(.*)\[(.*)\]$/', $key, $matches))
			{
				if (!isset($this->POST[$matches[1]]))
					$this->POST[$matches[1]] = array();

				if ($matches[2] == '')
					$this->POST[$matches[1]][] = $value;
				else
					$this->POST[$matches[1]][$matches[2]] = $value;
			}
			else
			{
				$this->POST[$key] = $value;
			}
		}
	}
	
	private function parseCOOKIE()
	{
		if (!isset($this->headers['Cookie']))
			return;
		
		$exp = explode(';', $this->headers['Cookie']);
		foreach ($exp as $v)
		{
			$exp2 = explode('=', $v);
			$this->COOKIE[urldecode(ltrim($exp2[0]))] = urldecode($exp2[1]);
		}
	}
}

class HttpResponse
{
	static $responseCodes	= array
		(
			200 => 'OK',
			400 => 'Bad Request',
			404 => 'File Not Found',
			411 => 'Length Required',
			413 => 'Request Entity Too Large',
			414 => 'Request-URI Too Long',
			415 => 'Unsupported Media Type',
		);

	private $responseCode	= 200;
	private $httpVersion	= '1.1';
	private $headers		= array('Content-Type' => 'text/html');
	private $cookies		= array();
	private $body			= '';
	private $bodyLen		= 0;
		
	public function __construct($httpVersion = '1.1', $code = 200)
	{
		$this->httpVersion = $httpVersion;
		$this->setResponseCode($code);
	}
	
	public function setResponseCode($code)
	{
		$this->responseCode = $code;
	}
	
	public function getResponseCode()
	{
		return $this->responseCode;
	}
	
	public function addHeader($header)
	{
		// Parse the header (validate it)
		$exp = explode(':', $header, 2);
		if (count($exp) != 2)
			return false;
		
		$exp[0] = trim($exp[0]);
		$exp[1] = trim($exp[1]);
		// Check for duplicate (can't do that the easy way because i want to do a case insensitive check)
		foreach ($this->headers as $k => $v)
		{
			if (strtolower($exp[0]) == strtolower($k))
			{
				unset($this->headers[$k]);
				break;
			}
		}
		
		// Store the header
		$this->headers[$exp[0]] = $exp[1];
	}
	
	public function getHeader($key)
	{
		return isset($this->headers[$key]) ? $this->headers[$key] : '';
	}
	
	public function getHeaders()
	{
		$this->finaliseHeaders();
		
		$headers = 'HTTP/'.$this->httpVersion.' '.$this->responseCode.' '.self::$responseCodes[$this->responseCode]."\r\n";
		foreach ($this->headers as $k => $v)
		{
			$headers .= $k.': '.$v."\r\n";
		}

		foreach ($this->cookies as $k => $v)
			$headers .= 'Set-Cookie: '.urlencode($k).'='.urlencode($v[0]).'; expires='.date('l, d-M-y H:i:s T', (int) $v[1]).'; path='.$v[2].'; domain='.$v[3].(($v[4]) ? '; secure' : '')."\r\n";

		return $headers."\r\n";
	}
	
	private function finaliseHeaders()
	{
		// Set server-side headers
		$this->headers['Server']				= 'PRISM v' . PHPInSimMod::VERSION;
		$this->headers['Date']					= date('r');
		if (!isset($this->headers['Content-Length']))
			$this->headers['Content-Length']	= $this->bodyLen;
		if ($this->responseCode == 200 || $this->responseCode == 404)
		{
			$this->headers['Connection']		= 'Keep-Alive';
			$this->headers['Keep-Alive']		= 'timeout='.HTTP_KEEP_ALIVE;
		}
	}
	
	public function addBody($data)
	{
		$this->body .= $data;
		$this->bodyLen += strlen($data);
	}
	
	public function getBody()
	{
		return $this->body;
	}
	
	public function setCookie($name, $value, $expire, $path, $domain, $secure = false, $httponly = false)
	{
		// Some value sanitation here, because it's user-input.
		$expire = (int) $expire;
		if ($path[0] != '/')
			$path = '/'.$path;
		
		$this->cookies[$name] = array($value, $expire, $path, $domain, $secure, $httponly);
	}
}

?>