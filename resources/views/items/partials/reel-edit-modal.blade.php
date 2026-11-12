{{-- Reel "Edit Video" modal (trim + thumbnail) for items/create and items/update; driven by each page's reel script. --}}
<div class="modal fade" id="reelEditVideoModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">{{ __('Edit Video') }}</h5>
                <button type="button" class="btn-close" id="reel-modal-x-btn"></button>
            </div>
            <div class="modal-body" style="overflow-x:hidden;">
                <div id="reel-step-1">
                    <div class="row g-3 mx-0">
                        <div class="col-12 col-md-5 px-2">
                            <p class="text-muted small mb-1"><i class="ph-bold ph-info me-1"></i>{{ __('Video Ads display in 9:16') }}</p>
                            <div style="aspect-ratio:9/16;background:#000;border-radius:8px;overflow:hidden;max-height:240px;">
                                <video id="reel-video-preview" controls style="width:100%;height:100%;object-fit:contain;display:block;"></video>
                            </div>
                        </div>
                        <div class="col-12 col-md-7 d-flex flex-column gap-3 px-2">
                            <div class="p-3 border rounded-3">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <span class="fw-semibold">{{ __('Video Timeline') }}</span>
                                    <button type="button" class="btn btn-sm btn-link p-0 text-primary text-decoration-none" id="reel-goto-thumb-btn">
                                        {{ __('Set Thumbnail') }} <i class="ph-bold ph-arrow-right ms-1"></i>
                                    </button>
                                </div>
                                <div id="reel-trimmer-wrap" dir="ltr" style="position:relative;border-radius:8px;overflow:visible;user-select:none;">
                                    <div id="reel-timeline" class="d-flex" style="border-radius:8px;overflow:hidden;"></div>
                                    <div id="reel-dim-left" style="display:none;position:absolute;top:0;left:0;height:100%;background:rgba(0,0,0,.55);pointer-events:none;border-radius:8px 0 0 8px;"></div>
                                    <div id="reel-dim-right" style="display:none;position:absolute;top:0;right:0;height:100%;background:rgba(0,0,0,.55);pointer-events:none;border-radius:0 8px 8px 0;"></div>
                                    <div id="reel-clip-window" style="display:none;position:absolute;top:-3px;height:calc(100% + 6px);border:3px solid #fff;border-radius:8px;cursor:grab;pointer-events:all;box-sizing:border-box;box-shadow:0 0 0 1px rgba(0,0,0,.3);">
                                        <div id="reel-handle-left" style="position:absolute;left:-2px;top:0;width:14px;height:100%;background:#fff;border-radius:6px 0 0 6px;cursor:ew-resize;display:flex;align-items:center;justify-content:center;z-index:2;"><div style="width:3px;height:20px;background:#aaa;border-radius:2px;"></div></div>
                                        <div id="reel-handle-right" style="position:absolute;right:-2px;top:0;width:14px;height:100%;background:#fff;border-radius:0 6px 6px 0;cursor:ew-resize;display:flex;align-items:center;justify-content:center;z-index:2;"><div style="width:3px;height:20px;background:#aaa;border-radius:2px;"></div></div>
                                        <div id="reel-playhead" style="position:absolute;top:0;left:4px;width:3px;height:100%;background:rgba(255,255,255,.9);pointer-events:none;border-radius:2px;"></div>
                                    </div>
                                </div>
                                <small class="text-muted mt-1 d-block" id="reel-clip-info">{{ __('Drag window to select clip') }}</small>
                                <input type="hidden" name="reel_start_time" id="reel-start-time" value="0">
                            </div>
                        </div>
                    </div>
                </div>
                <div id="reel-step-2" style="display:none;">
                    <div class="d-flex align-items-center mb-3">
                        <button type="button" class="btn btn-sm btn-link p-0 text-secondary text-decoration-none me-2" id="reel-back-to-step1-btn">
                            <i class="ph-bold ph-arrow-left me-1"></i>{{ __('Back') }}
                        </button>
                        <span class="fw-semibold">{{ __('Video Ads Thumbnails') }}</span>
                    </div>
                    <div class="row g-3 mx-0">
                        <div class="col-12 col-md-5">
                            <div style="aspect-ratio:9/16;background:#111;border-radius:8px;overflow:hidden;border:2px solid var(--bs-primary);position:relative;max-height:240px;">
                                <img id="reel-thumb-img" src="" alt="" style="width:100%;height:100%;object-fit:cover;display:none;">
                                <div id="reel-thumb-placeholder" style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;color:#555;">
                                    <i class="ph-bold ph-image" style="font-size:36px;"></i>
                                    <small class="mt-1">{{ __('Select a frame') }}</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-7 d-flex flex-column gap-3">
                            <div class="p-3 border rounded-3">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <span class="fw-semibold">{{ __('Select Thumbnail') }}</span>
                                    <button type="button" class="btn btn-sm btn-link p-0 text-primary text-decoration-none" id="reel-thumb-upload-btn">
                                        {{ __('Upload Image') }} <i class="ph-bold ph-upload-simple ms-1"></i>
                                    </button>
                                    <input type="file" id="reel-thumb-custom-input" class="d-none" accept="image/*">
                                </div>
                                <div id="reel-thumb-strip" dir="ltr" class="d-flex gap-1" style="border-radius:8px;overflow:hidden;min-height:70px;"></div>
                                <small class="text-muted mt-1 d-block">{{ __('Tap a frame to set as thumbnail') }}</small>
                            </div>
                        </div>
                    </div>
                </div>
                <canvas id="reel-thumb-canvas" style="display:none;"></canvas>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="reel-modal-cancel-btn">{{ __('Cancel') }}</button>
                <button type="button" class="btn btn-primary" id="reel-edit-done-btn">{{ __('Done') }}</button>
            </div>
        </div>
    </div>
</div>
