<?php
namespace Chocoholics\LaravelElasticEmail;

use GuzzleHttp\ClientInterface;
use Illuminate\Mail\Transport\Transport;
use Swift_Mime_SimpleMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Exception;
use App;

class ElasticTransport extends Transport
{

    /**
     * Guzzle client instance.
     *
     * @var \GuzzleHttp\ClientInterface
     */
    protected $client;

    /**
     * The Elastic Email API key.
     *
     * @var string
     */
    protected $key;

    /**
     * The Elastic Email username.
     *
     * @var string
     */
    protected $account;

    /**
     * THe Elastic Email API end-point.
     *
     * @var string
     */
    protected $url = 'https://api.elasticemail.com/v2/email/send';

    /**
     * Save the path of the files
     */
    protected $files = [];

    /**
     * Create a new Elastic Email transport instance.
     *
     * @param  \GuzzleHttp\ClientInterface  $client
     * @param  string  $key
     * @param  string  $username
     *
     * @return void
     */
    public function __construct(ClientInterface $client, array $config)
    {
        extract($config);
        $this->client = $client;
        $this->key = $key;
        $this->account = $account;
        $this->transactional = $transactional ?? true;
    }

    /**
     * {@inheritdoc}
     */
    public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null)
    {
        $this->beforeSendPerformed($message);
        $headers = $message->getHeaders();
        if ($headers->has('x-config-account')) {
            $this->account = (string) $headers->get('x-config-account')->getFieldBody();
            $headers->remove('x-config-account');
        }

        if ($headers->has('x-config-key')) {
            $this->key = (string) $headers->get('x-config-key')->getFieldBody();
            $headers->remove('x-config-key');
        }

        if ($headers->has('x-config-transactional')) {
            $this->transactional = (int) !empty($headers->get('x-config-transactional')->getFieldBody());
            $headers->remove('x-config-transactional');
        }

        $data = [
            'api_key' => $this->key,
            'account' => $this->account,
            'msgTo' => $this->getEmailAddresses($message),
            'msgCC' => $this->getEmailAddresses($message, 'getCc'),
            'msgBcc' => $this->getEmailAddresses($message, 'getBcc'),
            'msgFrom' => $this->getFromAddress($message)['email'],
            'msgFromName' => $this->getFromAddress($message)['name'],
            'from' => $this->getFromAddress($message)['email'],
            'fromName' => $this->getFromAddress($message)['name'],
            'to' => $this->getEmailAddresses($message),
            'subject' => $message->getSubject(),
            'body_html' => $message->getBody(),
            'body_text'       => $this->getText($message),
            'isTransactional' => $this->transactional,
            'files'           => $this->files($message->getChildren()),
            'lang' => App::getLocale()
        ];

        $replyTo = $message->getReplyTo();
        if (!empty($replyTo)) {
            $data['replyTo'] = key($replyTo);
        }

        $this->sendMail($data);
    }

    /**
     * Get the plain text part.
     *
     * @param  \Swift_Mime_SimpleMessage $message
     * @return text|null
     */
    protected function getText(Swift_Mime_SimpleMessage $message)
    {
        $text = null;

        foreach ($message->getChildren() as $child) {
            if ($child->getContentType() == 'text/plain') {
                $text = $child->getBody();
            }
        }

        if ($text == null) {
            $text = strip_tags($message->getBody());
        }

        return $text;
    }

    /**
     * @param \Swift_Mime_SimpleMessage $message
     *
     * @return array
     */
    protected function getFromAddress(Swift_Mime_SimpleMessage $message)
    {
        return [
            'email' => array_keys($message->getFrom())[0],
            'name' => array_values($message->getFrom())[0],
        ];
    }

    protected function getEmailAddresses(Swift_Mime_SimpleMessage $message, $method = 'getTo')
    {
        $data = call_user_func([$message, $method]);

        if (is_array($data)) {
            return implode(',', array_keys($data));
        }
        return '';
    }

    /**
     * Check Swift_Attachment count
     * @param $attachments
     * @return bool
    */
    public function files($attachments)
    {
        //solo attachement
        $files = array_filter($attachments, function ($e) {
            return $e instanceof \Swift_Attachment && $e->getDisposition() == 'attachment';
        });

        if (empty($files)) {
            return null;
        }

        $data = [];
        $i = 1;
        foreach ($files as $attachment) {
            $attachedFile = $attachment->getBody();
            $fileName = $attachment->getFilename();
            $ext = pathinfo($fileName, PATHINFO_EXTENSION);
            $attachedFilePath = tempnam(sys_get_temp_dir(), 'Tux').".{$ext}";
            file_put_contents($attachedFilePath, $attachedFile);
            $type = $attachment->getContentType();
            $data[] = [
                'name'     => "file_{$i}",
                'contents' => $attachedFilePath,
                'filename' => $fileName,
            ];
            $i++;
        }
        return $data;
    }

    public function attachmentParam(array $data)
    {
        $obj = $this;
        $p = [];
        //create attachment param
        foreach ($data['files'] as $key => $i) {
            if (!is_readable($i['contents'])) {
                continue;
            }
            $obj->files[] = $i['contents'];
            $i['contents'] = fopen($i['contents'], 'r');
            $p[] = $i;
        }

        unset($data['files']);
        foreach ($data as $key => $value) {
            $p[] = [
                'name'     => $key,
                'contents' => $value,
            ];
        }

        return [
            'multipart' =>  $p
        ];
    }

    public function withoutAttachment(array $data)
    {
        unset($data['files']);
        return [
            'form_params' => $data
        ];
    }

    public function sendMail(array $data, $resend = true)
    {
        $params = $data['files'] ?
            $this->attachmentParam($data) :
            $this->withoutAttachment($data);
        try {
            $result = $this->client->post($this->url, $params);
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $response = $e->getResponse();
            $responseBodyAsString = $response->getBody()->getContents();
            Log::error(["Error Elastic Email", $responseBodyAsString, $params]);
            return false;
        }
        $result = $this->client->post($this->url, $params);
        $body = $result->getBody();
        $obj  = json_decode($body->getContents());
        if (!empty($data['lang'])) {
            App::setLocale($data['lang']);
        }
       
        $to = json_encode($data['to']);
        if (empty($obj->success)) {
            Log::warning("Error Elastic Email: $obj->error, email: $to");
            //intenta reenviar sin adjunto
            if ($data['files'] && $resend) {
                Log::warning('Resend without attachment');
                $data['files'] =  null;
                $this->sendMail($data, false);
            } else if (!$resend) {
                Log::error("Error Elastic Email: email: $to, no se envió");
            }
            return false;
        } else {
            $this->cleanFiles();
            return true;
        }
    }

    public function cleanFiles()
    {
        foreach ($this->files as $key => $v) {
            if (is_readable($v)) {
                unlink($v);
            }
        }

        $this->files = [];
    }
}
