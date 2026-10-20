<?php
namespace PortOpener\Http\Middleware;use Closure;use Illuminate\Http\Request;
class RequireServiceHttps {public function handle(Request $request,Closure $next){abort_unless($request->isSecure(),400,'HTTPS is required.');abort_if((int)$request->headers->get('Content-Length',0)>262144 || strlen($request->getContent())>262144,413,'Service request is too large.');return $next($request);}}
