<?php
namespace Chocoholics\LaravelElasticEmail;

use GuzzleHttp\ClientInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Exception;
use App;

class ElasticTransport implements TransportInterface
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
     * The Elastic config
     *
     * @var array
     */
    protected $config = [];

    /**
     * Create a new Elastic Email transport instance.
     *
     * @param \GuzzleHttp\ClientInterface  $client
     * @param array  $config
     *
     * @return void
     */
    public function __construct(ClientInterface $client, array $config)
    {
        $this->client = $client;
        $this->config = $config;
    }

    /**
     * {@inheritdoc}
     */
    public function send(RawMessage $message, ?Envelope $envelope = null) : ?\Symfony\Component\Mailer\SentMessage
    {
        // reset on each sent
        $config = (object) $this->config;
        $headers = $message->getHeaders();
        $this->account = $config->account;
        if ($headers->has('x-config-account')) {
            $this->account = (string) $headers->get('x-config-account')->getValue();
            $headers->remove('x-config-account');
        }

        $this->key = $config->key;
        if ($headers->has('x-config-key')) {
            $this->key = (string) $headers->get('x-config-key')->getValue();
            $headers->remove('x-config-key');
        }

        $this->transactional = $config->transactional;
        if ($headers->has('x-config-transactional')) {
            $this->transactional = (int) !empty($headers->get('x-config-transactional')->getValue());
            $headers->remove('x-config-transactional');
        }

        $msgTo = array_reduce(
            $message->getTo(),
            function ($carry, $item) {
                // $carry is the result of the previous iteration; initially it's empty
                // trim the trailing semicolon from the previous iteration and concatenate the next item
                return trim("$carry;" . $item->getAddress(), ';');
            },
            ''
        );

        $from = current($message->getFrom());
        if (empty($msgTo)) {
            \Log::debug(['No se especificó destinatario', $message]);
            throw new Exception('No se especificó destinatario');
        }

        if (empty($from)) {
            \Log::debug(['No se especificó remitente', $message]);
            throw new Exception('No se especificó remitente');
        }

        $data = [
            'api_key' => $this->key,
            'account' => $this->account,
            'msgTo' => $msgTo,
            'msgCC' => $this->getEmailAddresses($message, 'getCc'),
            'msgBcc' => $this->getEmailAddresses($message, 'getBcc'),
            'msgFrom' => $from->getAddress(),
            'msgFromName' => $from->getName(),
            'subject' => $message->getSubject(),
            'isTransactional' => $this->transactional,
            'files'           => $this->files($message),
            'lang' => App::getLocale(),
            'bodyHtml' => $message->getHtmlBody(),
            'bodyText'       =>$message->getTextBody(),
        ];

        $replyTo = $message->getReplyTo();
        if (!empty($replyTo)) {
            $data['replyTo'] = current($replyTo)->getAddress();
        }

        // info for debug
        Log::debug([
            'ElasticTransport',
            $this->account,
            $this->key,
            $msgTo,
            $data['subject'],
        ]);

        $this->sendMail($data);
        return new SentMessage($message, $envelope);
    }

    protected function getEmailAddresses(RawMessage $message, $method = 'getTo')
    {
        $data = call_user_func([$message, $method]);
        if (is_array($data)) {
            $emails = array_filter(array_keys($data));
            return implode(',', $emails);
        }
        return '';
    }

    /**
     * Check Swift_Attachment count
     * @param $attachments
     * @return bool
    */
    public function files(RawMessage $message)
    {
        //solo attachement
        $files = $message->getAttachments();

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
            $obj  = json_decode($result->getBody()->getContents());
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $response = $e->getResponse();
            $responseBodyAsString = $response->getBody()->getContents();
            $data['files'] = null;
            $p = print_r($data, true);
            throw new \RuntimeException("Error Elastic Email: $responseBodyAsString, $p");
        }

        if (!empty($data['lang'])) {
            App::setLocale($data['lang']);
        }

        $to = json_encode($data['msgTo']);
        if (empty($obj->success)) {
            Log::warning(["Error Elastic Email email: $to", $obj]);
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

    public function __toString(): string
    {
        return 'elasticemail';
    }
}
