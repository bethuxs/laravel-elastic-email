<?php

namespace Chocoholics\LaravelElasticEmail;

use GuzzleHttp\ClientInterface;
use Illuminate\Mail\Transport\Transport;
use Swift_Mime_SimpleMessage;
use Illuminate\Support\Facades\Log;

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
     * Create a new Elastic Email transport instance.
     *
     * @param  \GuzzleHttp\ClientInterface  $client
     * @param  string  $key
     * @param  string  $username
	 *
     * @return void
     */
    public function __construct(ClientInterface $client, $key, $account, $model, $rate)
    {
    	$this->client = $client;
        $this->key = $key;
        $this->account = $account;
        $this->rate    = $rate;
        $this->model   =  $model;
    }

    /**
     * {@inheritdoc}
     */
    public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null)
    {
        $this->beforeSendPerformed($message);

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
	        'body_text' => $this->getText($message)
        ];

        $model = new $this->model();
        $model->data= json_encode($data);
        return $model->save();
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

        foreach($message->getChildren() as $child)
		{
			if($child->getContentType() == 'text/plain')
			{
				$text = $child->getBody();
			}
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

		if(is_array($data))
		{
			return implode(',', array_keys($data));
		}
		return '';
	}

    public function sendQueue(){
        $model = $this->model;
        $emails = $model::whereNull('send_at')
            ->orderBy('created_at', 'asc')
            ->take($this->rate)
            ->get();

        //delete old
        $model::where('send_at', '<',  date("Y-m-d H:i:s", strtotime("-1 day")))->delete();

        foreach ($emails as $e) {
            $result = $this->client->post($this->url, [
                'form_params' => $e->data
            ]);
            $body = $result->getBody();
            $obj  = json_decode($body->getContents());

            if(empty($obj->success)){
                Log::warning($this->error);
            }else{
                $e->send_at = date("Y-m-d H:i:s");
            }
            $e->save();
        }
        
    }
}
