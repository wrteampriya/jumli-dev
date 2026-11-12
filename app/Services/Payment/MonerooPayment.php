<?php

declare(strict_types=1);

namespace App\Services\Payment;

use Moneroo\Payment as MonerooPaymentSDK;
use Moneroo\Exceptions\InvalidPayloadException;
use Moneroo\Exceptions\ForbiddenException;
use Moneroo\Exceptions\UnauthorizedException;
use Moneroo\Exceptions\ServerErrorException;
use Moneroo\Exceptions\ServiceUnavailableException;
use Moneroo\Exceptions\InvalidAmountException;
use Exception;
use Illuminate\Support\Facades\Log;

final class MonerooPayment implements PaymentInterface
{
    private MonerooPaymentSDK $monerooPayment;
    private string $currencyCode;
    private string $publicKey;
    private string $secretKey;

    public function __construct(string $secretKey, string $publicKey, string $currencyCode)
    {
        $this->secretKey = $secretKey;
        $this->publicKey = $publicKey;
        $this->currencyCode = $currencyCode;
        $this->monerooPayment = new MonerooPaymentSDK($secretKey);
    }

    public function createPaymentIntent($amount, $customMetaData)
    {
        try {
             Log::info('Moneroo: Incoming payment details', [
            'currency_code' => $this->currencyCode,
            'amount_before_validation' => $amount,
        ]);
            
            $amount = $this->minimumAmountValidation($this->currencyCode, $amount);
          
            if ($amount <= 0) {
                throw new InvalidAmountException('Amount must be greater than 0');
            }
             $metadata = array_merge($customMetaData, [
            'original_amount' => (string) $amount,
            'original_currency' => (string) $this->currencyCode,
            'converted_amount' => (string) $amount,
            'converted_currency' => (string) $this->currencyCode,
        ]);

        // Ensure metadata contains only supported scalar values.
        $metadata = array_filter(
            $metadata,
            fn ($value) => is_string($value)
                || is_int($value)
                || is_bool($value)
        );

        $metadata = $this->limitMetadataToTenItems($metadata);
            $paymentData = [
                'amount' => $amount,
                'currency' => $this->currencyCode,
                'reference' => 'TXN_' . time() . '_' . ($customMetaData['payment_transaction_id'] ?? uniqid()),
                'customer' => [
                    'email' => $customMetaData['email'] ?? 'customer@example.com',
                    'first_name' => $this->extractFirstName($customMetaData['name'] ?? 'Customer'),
                    'last_name' => $this->extractLastName($customMetaData['name'] ?? 'Customer'),
                ],
                'return_url' => config('app.url') . '/response/moneroo/success/web',
                'callback_url' => config('app.url') . '/webhook/moneroo',
                'description' => $customMetaData['description'] ?? 'Payment for services',
                    'metadata' => $this->limitMetadataToTenItems(array_merge($customMetaData, [
                    'original_amount' => $amount,
                    'original_currency' => $this->currencyCode,
                    'converted_amount' => $amount,
                    'converted_currency' => $this->currencyCode,
                ])),
                  'metadata' => $metadata,
            ];

            return $this->monerooPayment->init($paymentData);
            
        } catch (InvalidPayloadException $e) {
            $errorMessage = 'Moneroo validation error: ' . $e->getMessage();
            if (method_exists($e, 'getResponse') && $e->getResponse()) {
                $response = $e->getResponse();
                if (method_exists($response, 'getBody')) {
                    $body = $response->getBody()->getContents();
                    $errorMessage .= ' Response: ' . $body;
                }
            }
            throw new Exception($errorMessage);
        } catch (ForbiddenException | UnauthorizedException | ServerErrorException | ServiceUnavailableException $e) {
            throw $e;
        } catch (Exception $e) {
            throw new Exception('Failed to create Moneroo payment: ' . $e->getMessage());
        }
    }

    public function createAndFormatPaymentIntent($amount, $customMetaData): array
    {
        $paymentIntent = $this->createPaymentIntent($amount, $customMetaData);  
        return $this->format($paymentIntent);
    }

    public function retrievePaymentIntent($paymentId): array
    {
        try {
            $paymentIntent = $this->monerooPayment->get($paymentId);
            return $this->format($paymentIntent);
        } catch (InvalidPayloadException | ForbiddenException | UnauthorizedException | ServerErrorException | ServiceUnavailableException $e) {
            throw $e;
        } catch (Exception $e) {
            throw new Exception('Failed to retrieve Moneroo payment: ' . $e->getMessage());
        }
    }

    public function format($paymentIntent): array
    {
        $amount = $paymentIntent->amount ?? 0;
        if ($amount == 0 && isset($paymentIntent->metadata['original_amount'])) {
            $amount = $paymentIntent->metadata['original_amount'];
        }
        
        return $this->formatPaymentIntent(
            $paymentIntent->id ?? $paymentIntent->transaction_id,
            $amount,
            $paymentIntent->currency ?? $this->currencyCode,
            $paymentIntent->status ?? 'pending',
            $paymentIntent->metadata ?? [],
            $paymentIntent
        );
    }

    public function formatPaymentIntent($id, $amount, $currency, $status, $metadata, $paymentIntent): array
    {
        $mappedStatus = match ($status) {
            'completed', 'succeeded' => 'succeed',
            'failed', 'cancelled', 'expired' => 'failed',
            'pending', 'processing', 'initiated' => 'pending',
            default => 'pending'
        };

        return [
            'id' => $id,
            'amount' => $amount,
            'currency' => $currency,
            'metadata' => $metadata,
            'status' => $mappedStatus,
            'payment_gateway_response' => $paymentIntent
        ];
    }

    public function minimumAmountValidation($currency, $amount)
    {
        // For Moneroo, we handle minimum amounts in convertAmountForCurrency
        // This method is required by the interface but not used in our implementation
        return $amount;
    }

    public function verifyPayment(string $transactionId)
    {
        try {
            return $this->monerooPayment->verify($transactionId);
        } catch (InvalidPayloadException | ForbiddenException | UnauthorizedException | ServerErrorException | ServiceUnavailableException $e) {
            throw $e;
        } catch (Exception $e) {
            throw new Exception('Failed to verify Moneroo payment: ' . $e->getMessage());
        }
    }

    public function markAsProcessed(string $transactionId)
    {
        try {
            return $this->monerooPayment->makeAsProcessed($transactionId);
        } catch (InvalidPayloadException | ForbiddenException | UnauthorizedException | ServerErrorException | ServiceUnavailableException $e) {
            throw $e;
        } catch (Exception $e) {
            throw new Exception('Failed to mark Moneroo payment as processed: ' . $e->getMessage());
        }
    }

    private function extractFirstName(string $fullName): string
    {
        $nameParts = explode(' ', trim($fullName));
        return $nameParts[0] ?? 'Customer';
    }

    private function extractLastName(string $fullName): string
    {
        $nameParts = explode(' ', trim($fullName));
        if (count($nameParts) > 1) {
            return implode(' ', array_slice($nameParts, 1));
        }
        return 'Customer';
    }

    private function limitMetadataToTenItems(array $metadata): array
    {
        $filteredMetadata = array_filter($metadata, function($value) {
            return $value !== null && $value !== '';
        });
        
        if (count($filteredMetadata) <= 10) {
            return $filteredMetadata;
        }
        
        $priorityKeys = [
            'payment_transaction_id',
            'package_id',
            'user_id',
            'email',
            'name',
            'mobile',
            'phone',
            'original_amount',
            'original_currency',
            'converted_amount'
        ];
        
        $limitedMetadata = [];
        
        foreach ($priorityKeys as $key) {
            if (isset($filteredMetadata[$key]) && count($limitedMetadata) < 10) {
                $limitedMetadata[$key] = $filteredMetadata[$key];
            }
        }
        
        foreach ($filteredMetadata as $key => $value) {
            if (!isset($limitedMetadata[$key]) && count($limitedMetadata) < 10) {
                $limitedMetadata[$key] = $value;
            }
        }
        
        return $limitedMetadata;
    }

} 