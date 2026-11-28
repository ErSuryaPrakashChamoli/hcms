{{-- The variables a Word payslip layout can use. --}}
<div class="pos-psd-vars">
    <p class="pos-psd-preview-title">Payslip variables</p>
    <dl>
        @foreach ($variables as $variable => $what)
            <div><dt><code>{{ '{'.'{ '.$variable.' }'.'}' }}</code></dt><dd>{{ $what }}</dd></div>
        @endforeach
    </dl>
</div>
