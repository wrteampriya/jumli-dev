<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use App\Services\CachingService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class AddWatermarkJob implements ShouldQueue
{
    ////Moneroo Payment customization
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $imagePath;
    //test
    public string $extension;
    public ?string $storagePath;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the job can run before timing out.
     *
     * @var int
     */
    public int $timeout = 300; // 5 minutes

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var int
     */
    public int $backoff = 60; // Wait 60 seconds before retry

    /**
     * Track temporary files to clean up after processing.
     */
    private array $tempFiles = [];

    /**
     * Create a new job instance.
     *
     * @param string $imagePath   Local absolute path or temp path to the image
     * @param string $extension   Image file extension
     * @param string|null $storagePath  Original storage-relative path (e.g. "item_images/file.png") for remote disks
     */
    public function __construct(string $imagePath, string $extension, ?string $storagePath = null)
    {
        $this->imagePath = $imagePath;
        $this->extension = $extension;
        $this->storagePath = $storagePath;
    }

    /**
     * Resolve a storage-relative path to a local filesystem path.
     * For local disk: returns the real absolute path.
     * For remote disk (S3, etc.): downloads the file to a temp path and returns that.
     *
     * @param string $disk
     * @param string $relativePath  The storage-relative key (e.g. "settings/logo.png")
     * @return string|null  Local filesystem path, or null if file not found.
     */
    private function getLocalPath(string $disk, string $relativePath): ?string
    {
        if (!Storage::disk($disk)->exists($relativePath)) {
            return null;
        }

        if ($disk === 'local' || $disk === 'public') {
            // Local disks — path() returns a real filesystem path
            $localPath = Storage::disk($disk)->path($relativePath);
            if (file_exists($localPath)) {
                return $localPath;
            }
            return null;
        }

        // Remote disk (s3, etc.) — download to a temp file
        try {
            $contents = Storage::disk($disk)->get($relativePath);
            if ($contents === null || $contents === false) {
                return null;
            }
            $ext = pathinfo($relativePath, PATHINFO_EXTENSION);
            $tempPath = sys_get_temp_dir() . '/' . uniqid('wm_', true) . '.' . $ext;
            file_put_contents($tempPath, $contents);
            $this->tempFiles[] = $tempPath; // Track for cleanup
            return $tempPath;
        } catch (\Exception $e) {
            Log::error('Failed to download file from storage', [
                'disk' => $disk,
                'path' => $relativePath,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Clean up any temporary files created during processing.
     */
    private function cleanupTempFiles(): void
    {
        foreach ($this->tempFiles as $tempFile) {
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }
        $this->tempFiles = [];
    }

    public function handle(): void
    {
        Log::info('AddWatermarkJob started', [
            'imagePath' => $this->imagePath,
            'extension' => $this->extension,
            'file_exists' => file_exists($this->imagePath),
        ]);
        
        try {
            $startTime = microtime(true);
            $disk = config('filesystems.default');

            // Get all settings from cache
            $settings = CachingService::getSystemSettings()->toArray();

            // Check if watermark is enabled
            $watermarkEnabled = isset($settings['watermark_enabled']) && (int)$settings['watermark_enabled'] === 1;
            if (!$watermarkEnabled) {
                Log::info('Watermark is disabled, skipping watermark job');
                return;
            }
            
            // Log::info('Watermark is enabled, processing job');
            
            // Helper function to extract relative path from URL or return path as is
            $extractPathFromUrl = function($pathOrUrl) {
                if (empty($pathOrUrl)) {
                    return null;
                }
                
                // If it's a URL, extract the path after /storage/
                if (filter_var($pathOrUrl, FILTER_VALIDATE_URL)) {
                    $parsedUrl = parse_url($pathOrUrl);
                    $path = $parsedUrl['path'] ?? '';
                    
                    // Extract path after /storage/
                    if (preg_match('#/storage/(.+)$#', $path, $matches)) {
                        return $matches[1];
                    }
                    
                    // If /storage/ not found, try to extract from path
                    if (preg_match('#/([^/]+/.+)$#', $path, $matches)) {
                        return $matches[1];
                    }
                }
                
                // Return as is if it's already a relative path
                return $pathOrUrl;
            };
            
            // ── Resolve watermark image to a local file path ──────────────
            $watermarkImage = $settings['watermark_image'] ?? null;
            $watermarkLocalPath = null;

            if (!empty($watermarkImage)) {
                $watermarkRelativePath = $extractPathFromUrl($watermarkImage);
                
                if ($watermarkRelativePath) {
                    // Try loading from configured storage disk (works for both local & S3)
                    $watermarkLocalPath = $this->getLocalPath($disk, $watermarkRelativePath);
                    if ($watermarkLocalPath) {
                        // Log::info('Watermark found in storage', ['path' => $watermarkRelativePath]);
                    }
                }

                // Fallback: try as a direct filesystem path
                if (!$watermarkLocalPath && file_exists($watermarkImage)) {
                    $watermarkLocalPath = $watermarkImage;
                    // Log::info('Watermark found as direct path', ['path' => $watermarkLocalPath]);
                }

                // Fallback: try public path
                if (!$watermarkLocalPath) {
                    $publicPath = public_path('assets/images/logo/' . basename($watermarkImage));
                    if (file_exists($publicPath)) {
                        $watermarkLocalPath = $publicPath;
                        // Log::info('Watermark found in public path', ['path' => $watermarkLocalPath]);
                    }
                }
            }

            // If watermark image not found, try company logo
            if (!$watermarkLocalPath) {
                Log::info('Watermark image not found, trying company logo');
                $companyLogo = $settings['company_logo'] ?? null;
                
                if (!empty($companyLogo)) {
                    $companyLogoRelativePath = $extractPathFromUrl($companyLogo);
                    
                    if ($companyLogoRelativePath) {
                        $watermarkLocalPath = $this->getLocalPath($disk, $companyLogoRelativePath);
                        if ($watermarkLocalPath) {
                            // Log::info('Company logo found in storage', ['path' => $companyLogoRelativePath]);
                        }
                    }

                    if (!$watermarkLocalPath && file_exists($companyLogo)) {
                        $watermarkLocalPath = $companyLogo;
                        // Log::info('Company logo found as direct path', ['path' => $watermarkLocalPath]);
                    }

                    if (!$watermarkLocalPath) {
                        $publicPath = public_path('assets/images/logo/' . basename($companyLogo));
                        if (file_exists($publicPath)) {
                            $watermarkLocalPath = $publicPath;
                            // Log::info('Company logo found in public path', ['path' => $watermarkLocalPath]);
                        }
                    }
                } else {
                    $defaultLogo = public_path('assets/images/logo/logo.png');
                    if (file_exists($defaultLogo)) {
                        $watermarkLocalPath = $defaultLogo;
                        // Log::info('Using default logo path', ['path' => $watermarkLocalPath]);
                    }
                }
            }

            if (!$watermarkLocalPath || !file_exists($watermarkLocalPath)) {
                // Log::warning('No watermark image could be resolved, skipping', [
                //     'watermark_image' => $watermarkImage ?? 'null',
                //     'company_logo' => $settings['company_logo'] ?? 'null',
                // ]);
                $this->cleanupTempFiles();
                return;
            }

            // ── Load the main image (local or remote) ─────────────────────
            if (file_exists($this->imagePath)) {
                // Local file (e.g. temp file from FileService::getAbsolutePath)
                $image = Image::make($this->imagePath);
                // Log::info('Main image loaded from local path', ['path' => $this->imagePath]);
            } else {
                // Try loading from configured storage disk
                $localMainImage = $this->getLocalPath($disk, $this->imagePath);
                if ($localMainImage) {
                    $image = Image::make($localMainImage);
                    // Log::info('Main image loaded from storage', ['path' => $this->imagePath]);
                } else {
                    // Log::error('AddWatermarkJob: image not found anywhere', [
                    //     'path' => $this->imagePath,
                    //     'disk' => $disk,
                    // ]);
                    $this->cleanupTempFiles();
                    return;
                }
            }
            
            // Get watermark settings with validation
            $opacity = isset($settings['watermark_opacity']) ? (int)$settings['watermark_opacity'] : 25;
            $size = isset($settings['watermark_size']) ? (int)$settings['watermark_size'] : 10;
            $style = $settings['watermark_style'] ?? 'tile';
            $position = $settings['watermark_position'] ?? 'center';
            $rotation = isset($settings['watermark_rotation']) ? (int)$settings['watermark_rotation'] : 0;
            
            // Validate and clamp values to safe ranges
            $opacity = max(0, min(100, $opacity)); // Clamp between 0-100
            $size = max(1, min(100, $size)); // Clamp between 1-100
            $rotation = max(-360, min(360, $rotation)); // Clamp between -360 to 360
            
            // Normalize rotation to -180 to 180 range for efficiency
            while ($rotation > 180) $rotation -= 360;
            while ($rotation < -180) $rotation += 360;
            
            // Validate style
            if (!in_array($style, ['tile', 'single', 'center'])) {
                $style = 'tile';
            }
            
            // Validate position
            if (!in_array($position, ['top-left', 'top-right', 'bottom-left', 'bottom-right', 'center'])) {
                $position = 'center';
            }
            
            // If style is 'center', force position to center
            if ($style === 'center') {
                $position = 'center';
            }
        
            
            // Intervention/Image rotates in the opposite direction compared to our UI/CSS preview.
            // To keep backend output consistent with the admin preview, invert the rotation sign.
            $appliedRotation = -$rotation;

            $originalWidth = $image->width();
            $originalHeight = $image->height();
            

            // Only resize very large images (over 3000px) to speed up processing
            // This maintains quality for most images while improving performance
            $maxDimension = 3000;
            $needsResize = false;

            if ($originalWidth > $maxDimension || $originalHeight > $maxDimension) {
                $needsResize = true;
                if ($originalWidth > $originalHeight) {
                    $image->resize($maxDimension, null, fn($c) => $c->aspectRatio());
                } else {
                    $image->resize(null, $maxDimension, fn($c) => $c->aspectRatio());
                }
            }

            // Load and prepare watermark
            $watermark = Image::make($watermarkLocalPath);
            $watermarkOriginalWidth = $watermark->width();
            $watermarkOriginalHeight = $watermark->height();
            
            // Validate watermark dimensions
            if ($watermarkOriginalWidth <= 0 || $watermarkOriginalHeight <= 0) {
                $this->cleanupTempFiles();
                return;
            }
            
            // Calculate watermark size based on percentage
            // Ensure minimum size of 10px to prevent invisible watermarks
            $watermarkWidth = max(10, $image->width() * ($size / 100));
            // Also ensure it doesn't exceed image dimensions
            $watermarkWidth = min($watermarkWidth, $image->width());
            
            $watermark->resize($watermarkWidth, null, fn($c) => $c->aspectRatio());
            
            // Store dimensions before rotation for tiling calculations
            $watermarkWidthBeforeRotation = $watermark->width();
            $watermarkHeightBeforeRotation = $watermark->height();
            
            // Validate dimensions after resize
            if ($watermarkWidthBeforeRotation <= 0 || $watermarkHeightBeforeRotation <= 0) {
                $this->cleanupTempFiles();
                return;
            }
            
            // Apply opacity (only if > 0, otherwise skip processing)
            if ($opacity > 0) {
                $watermark->opacity($opacity);
            }
            
            // Rotate the watermark if needed
            if ($appliedRotation != 0) {
                $watermark->rotate($appliedRotation);
            }
            
            // Log::info('Watermark prepared', [
            //     'original_size' => "{$watermarkOriginalWidth}x{$watermarkOriginalHeight}",
            //     'resized_size' => "{$watermarkWidthBeforeRotation}x{$watermarkHeightBeforeRotation}",
            //     'after_rotation_size' => "{$watermark->width()}x{$watermark->height()}",
            //     'rotation' => $appliedRotation
            // ]);

            /**
             * 🧩 Apply watermark based on style
             */
            if ($style === 'tile') {
                // Use dimensions before rotation for spacing calculation to ensure proper coverage
                $baseSpacing = 1.5;
                $xStep = (int)($watermarkWidthBeforeRotation * $baseSpacing);
                $yStep = (int)($watermarkHeightBeforeRotation * $baseSpacing);
                
                // Ensure minimum step size to prevent infinite loops
                $xStep = max(1, $xStep);
                $yStep = max(1, $yStep);

                // Calculate number of tiles and optimize spacing if too many
                $tilesX = (int)ceil($image->width() / max(1, $xStep));
                $tilesY = (int)ceil($image->height() / max(1, $yStep));
                $totalTiles = $tilesX * $tilesY;

                // Limit to max 150 tiles for performance (adjust spacing if needed)
                $maxTiles = 150;
                if ($totalTiles > $maxTiles) {
                    $factor = sqrt($totalTiles / $maxTiles);
                    $xStep = max(1, (int)($xStep * $factor));
                    $yStep = max(1, (int)($yStep * $factor));
                    // Recalculate after adjustment
                    $tilesX = (int)ceil($image->width() / max(1, $xStep));
                    $tilesY = (int)ceil($image->height() / max(1, $yStep));
                }

                // Apply tiles - Intervention Image can reuse the same watermark object
                $tileCount = 0;
                try {
                    for ($y = 0; $y < $image->height(); $y += $yStep) {
                        for ($x = 0; $x < $image->width(); $x += $xStep) {
                            $image->insert($watermark, 'top-left', $x, $y);
                            $tileCount++;
                        }
                    }
                } catch (\Exception $e) {
                    throw $e;
                }
            } else {
                // Single watermark or center style
                $padding = 10;
                // Single watermark at specified position
                switch ($position) {
                    case 'top-left':
                        $image->insert($watermark, 'top-left', $padding, $padding);
                        break;
                    case 'top-right':
                        $image->insert($watermark, 'top-right', $padding, $padding);
                        break;
                    case 'bottom-left':
                        $image->insert($watermark, 'bottom-left', $padding, $padding);
                        break;
                    case 'bottom-right':
                        $image->insert($watermark, 'bottom-right', $padding, $padding);
                        break;
                    case 'center':
                    default:
                        $image->insert($watermark, 'center');
                        break;
                }
            }

            /**
             * 💾 Save image — works for both local and remote disks
             */
            $encodeFormat = strtolower($this->extension);
            if ($encodeFormat === 'jpg') {
                $encodeFormat = 'jpeg';
            }

            $encodedImage = $image->encode($encodeFormat, 85);

            if ($disk === 'local' || $disk === 'public') {
                // Local filesystem — write directly to the original path
                $encodedImage->save($this->imagePath);
                $saved = file_exists($this->imagePath);
            } else {
                // Remote filesystem (S3, etc.) — upload back using the original storage key
                $uploadKey = $this->storagePath ?? $this->imagePath;
                Storage::disk($disk)->put($uploadKey, (string) $encodedImage);
                $saved = Storage::disk($disk)->exists($uploadKey);
                // Log::info('Watermarked image uploaded to storage', [
                //     'disk' => $disk,
                //     'key' => $uploadKey,
                // ]);
            }

            if (!$saved) {
                throw new \Exception('Image file was not saved successfully');
            }

            if ($disk === 'local' || $disk === 'public') {
                $fileSize = filesize($this->imagePath);
            } else {
                $uploadKey = $this->storagePath ?? $this->imagePath;
                $fileSize = Storage::disk($disk)->size($uploadKey);
            }

            // Log::info('Watermark job completed successfully', [
            //     'image_path' => $this->imagePath,
            //     'storage_path' => $this->storagePath,
            //     'file_size' => $fileSize,
            //     'processing_time' => round(microtime(true) - $startTime, 2) . 's'
            // ]);
        } catch (\Throwable $e) {
            Log::error('Error in AddWatermarkJob: ' . $e->getMessage(), [
                'imagePath' => $this->imagePath,
                'trace' => $e->getTraceAsString(),
            ]);
        } finally {
            // Always clean up temp files, even on failure
            $this->cleanupTempFiles();
        }
    }
}
