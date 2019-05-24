<?php namespace Ipunkt\LaravelJaeger;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Ipunkt\LaravelJaegerRabbitMQ\MessageContext\SpanContext;
use Event;
use DB;
use Log;

/**
 * Class Provider
 * @package Ipunkt\LaravelJaeger
 */
class Provider extends ServiceProvider
{

    public function register()
    {
        $this->publishes([
            __DIR__.'/config/jaeger.php' => config_path('jaeger.php'),
        ]);

        // Setup a unique ID for each request. This will allow us to find
        // the request trace in the jaeger ui
        $this->app->instance('context', new SpanContext());
    }

    public function boot()
    {
    	app('context')->start();

        $this->setupQueryLogging();

	    $this->registerEvents();

	    $this->parseRequest();
    }

    protected function registerEvents(): void
    {
        // When the app terminates we must finish the global span
        // and send the trace to the jaeger agent.
        app()->terminating(function () {
	        app('context')->finish();
        });

        // Listen for each logged message and attach it to the global span
        Event::listen(MessageLogged::class, function (MessageLogged $e) {
	        app('context')->log((array)$e);
        });

        // Listen for the request handled event and set more tags for the trace
        Event::listen(RequestHandled::class, function (RequestHandled $e) {
	        app('context')->setPrivateTags([
                'user_id' => optional(auth()->user())->id ?? "-",
                'company_id' => optional(auth()->user())->company_id ?? "-",

                'request_host' => $e->request->getHost(),
                'request_path' => $path = $e->request->path(),
                'request_method' => $e->request->method(),

                'api' => Str::contains($path, 'api'),
                'response_status' => $e->response->getStatusCode(),
                'error' => !$e->response->isSuccessful(),
            ]);
        });
    }

	private function setupQueryLogging() {

		// Also listen for queries and log then,
		// it also receives the log in the MessageLogged event above
		DB::listen(function ($query, $values) {
			Log::debug("[DB Query] {$query->connection->getName()}", [
				'query' => str_replace('"', "'", $query->sql),
				'values' => $values,
				'time' => $query->time . 'ms',
			]);
		});
	}

	private function parseRequest() {

		if ( app()->runningInConsole() )
			return;

		app('context')->parse( request()->url(), request()->input() );
	}

}