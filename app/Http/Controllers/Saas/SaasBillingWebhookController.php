<?php

namespace App\Http\Controllers\Saas;

use App\Http\Controllers\Controller;
use App\Services\Saas\SaasStripeWebhookProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SaasBillingWebhookController extends Controller
{
    public function __invoke(Request $request, SaasStripeWebhookProcessor $processor): JsonResponse
    {
        if (!config('saas.enabled') || !config('saas.billing_enabled')) {
            return response()->json(['error' => 'unavailable'], 503);
        }

        try {
            $event = $processor->verifiedEvent((string) $request->getContent(), (string) $request->header('Stripe-Signature'));
        } catch (RuntimeException) {
            return response()->json(['error' => 'invalid signature'], 400);
        }

        try {
            $processor->process($event);
        } catch (Throwable $e) {
            Log::error('Stripe workspace billing event failed', [
                'event_id' => $event['id'],
                'error_type' => get_class($e),
            ]);

            return response()->json(['error' => 'processing failed'], 500);
        }

        return response()->json(['received' => true]);
    }
}
