{{-- The chosen design at full width in the colours and options on the form (not saved yet). --}}
<div class="pos-psd-preview" data-design-preview="{{ $design }}">
    <p class="pos-psd-preview-title">Preview · {{ $name }}</p>
    @if (filled($html))
        {{-- The frame's box keeps its height across Livewire updates (wire:ignore.self) and fits the page it holds: when a new
             page loads and when the step becomes visible (a srcdoc frame is same-origin, so its body's height — not the frame's — can be read). --}}
        <div class="pos-psd-frame" wire:ignore.self
            x-data="{ fit() { const f = this.$el.querySelector('iframe'); try { const h = Math.ceil(f.contentWindow.document.body.getBoundingClientRect().height) + 8; if (f.offsetWidth > 0 && h > 100 && Math.abs(this.$el.offsetHeight - h) > 2) { this.$el.style.height = h + 'px'; } } catch (e) {} } }"
            x-init="new ResizeObserver(() => fit()).observe($el)">
            <iframe wire:key="payslip-preview-{{ md5($html) }}" srcdoc="{{ $html }}" title="Payslip preview" x-on:load="fit()"></iframe>
        </div>
    @else
        <x-pos.state variant="empty" size="inline" title="Your Word layout has no on-screen preview."
            why="Payslips download as filled Word files. Use “Download a filled sample” under Your Word layout to check it." />
    @endif
</div>
