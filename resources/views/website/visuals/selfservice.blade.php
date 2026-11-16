<div class="ps-vis" style="max-width:340px">
    <div class="ps-phone">
        <div class="ps-phone-screen">
            <div class="ps-phone-top">
                <div class="ps-v-greet">Hi, Meera<small>Tuesday · General shift</small></div>
                <div class="ps-phone-punch"><div><span style="font-size:10.5px;opacity:.7;display:block">Not checked in</span><b>09:12</b></div><span class="ps-v-btn">Check in</span></div>
            </div>
            <div class="ps-phone-list">
                <div class="ps-v-row"><span class="ps-v-av" style="--c:#574bc4">{{ svg('heroicon-m-document-text') }}</span><span class="ps-v-grow"><b>October payslip</b><span>Net pay credited</span></span></div>
                <div class="ps-v-row"><span class="ps-v-av" style="--c:#8a6418">{{ svg('heroicon-m-sun') }}</span><span class="ps-v-grow"><b>Leave balance</b><span>12.5 days earned leave</span></span></div>
                <div class="ps-v-row"><span class="ps-v-av" style="--c:#0e6f7a">{{ svg('heroicon-m-currency-rupee') }}</span><span class="ps-v-grow"><b>Declare investments</b><span>Step 2 of 4 · due 30 Nov</span></span></div>
                <div class="ps-v-row"><span class="ps-v-av" style="--c:#1d7656">{{ svg('heroicon-m-check') }}</span><span class="ps-v-grow"><b>Laptop request</b><span>Approved · ready Thursday</span></span></div>
            </div>
        </div>
    </div>
    @include('website.visuals._float', ['pos' => 'left:-46px; top:150px', 'icon' => 'heroicon-m-bell', 'tone' => 'is-iris', 'title' => 'Leave approved', 'sub' => 'by Rohan · 2 min ago', 'late' => true])
</div>
