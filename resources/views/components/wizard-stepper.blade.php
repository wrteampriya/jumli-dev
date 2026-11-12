{{--
    Numbered wizard stepper. Each step is a Bootstrap tab link to its pane
    (`href="#pane"`), so page scripts keep driving steps with `.tab('show')`.
    Numbers come from a CSS counter: a hidden step (display:none) is skipped
    and the rest renumber. Completed steps get `.is-complete` from
    custom.js (steps before the active one).

    $steps: [['target' => 'module', 'label' => __('Module'), 'id' => 'module-step-item', 'hidden' => false], ...]
--}}
@props(['id', 'steps' => []])

<ul {{ $attributes->merge(['class' => 'nav ec-stepper', 'id' => $id, 'role' => 'tablist']) }}>
    @foreach ($steps as $index => $step)
        <li class="nav-item" @isset($step['id']) id="{{ $step['id'] }}" @endisset @if (!empty($step['hidden'])) style="display: none;" @endif>
            <a class="nav-link {{ $index === 0 ? 'active' : '' }}" data-bs-toggle="tab" href="#{{ $step['target'] }}" role="tab">
                <span class="ec-step-num" aria-hidden="true"><i class="ph-bold ph-check"></i></span>
                <span class="ec-step-label">{{ $step['label'] }}</span>
            </a>
        </li>
    @endforeach
</ul>
