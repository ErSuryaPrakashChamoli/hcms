<div class="ps-vis">
    <div class="ps-win">
        <div class="ps-win-bar"><i></i><i></i><i></i><span></span></div>
        <div class="ps-win-main" style="padding:20px; gap:14px">
            <div style="display:flex;gap:14px;align-items:center"><span class="ps-shield">{{ svg('heroicon-o-shield-check') }}</span><div class="ps-v-greet">Audit trail<small>Every change, chained to the one before</small></div></div>
            <div class="ps-chain">
                @foreach ([['Salary revised','Rohan Kapoor · effective 1 Dec','9f3a…c21e'],['Bank details updated','Meera Shah · verified','c21e…7b04'],['Role granted: Payroll admin','by Asha · reason recorded','7b04…e9d2'],['Signed in with SSO','two-step verified','e9d2…41aa']] as [$t,$s,$h])
                    <div class="ps-v-row"><span class="ps-v-av" style="--c:#574bc4">{{ svg('heroicon-m-link') }}</span><span class="ps-v-grow"><b>{{ $t }}</b><span>{{ $s }}</span></span><span class="ps-hash">{{ $h }}</span></div>
                @endforeach
            </div>
        </div>
    </div>
    @include('website.visuals._float', ['pos' => 'left:-20px; bottom:-14px', 'icon' => 'heroicon-m-key', 'tone' => 'is-iris', 'title' => 'Two-step verification', 'sub' => 'Required for administrators'])
</div>
