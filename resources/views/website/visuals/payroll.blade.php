<div class="ps-vis">
    <div class="ps-win">
        <div class="ps-win-bar"><i></i><i></i><i></i><span></span></div>
        <div class="ps-win-main" style="padding:20px; gap:16px">
            <div style="display:flex;justify-content:space-between;align-items:center"><div class="ps-v-greet">November payroll<small>Pay cycle 25 Oct – 24 Nov</small></div><span class="ps-v-pill is-iris">Review</span></div>
            <div class="ps-v-steps">
                <div class="ps-v-step"><i>{{ svg('heroicon-m-check') }}</i>Inputs</div>
                <div class="ps-v-step"><i>{{ svg('heroicon-m-check') }}</i>Checks</div>
                <div class="ps-v-step"><i>{{ svg('heroicon-m-check') }}</i>Calculate</div>
                <div class="ps-v-step is-now"><i>{{ svg('heroicon-m-eye') }}</i>Review</div>
                <div class="ps-v-step is-next"><i>{{ svg('heroicon-m-paper-airplane') }}</i>Publish</div>
            </div>
            <div class="ps-v-tiles">
                <div class="ps-v-tile"><span>Gross pay</span><b>₹4.82 Cr</b><em>+1.8% vs Oct</em></div>
                <div class="ps-v-tile"><span>Employees</span><b>1,284</b><em>12 joiners · 5 leavers</em></div>
                <div class="ps-v-tile"><span>Checks</span><b>0</b><em>All clear</em></div>
            </div>
            <div class="ps-v-row"><span class="ps-v-av" style="--c:#1d7656">{{ svg('heroicon-m-check') }}</span><span class="ps-v-grow"><b>Pre-payroll checks passed</b><span>Attendance locked · no missing bank details</span></span></div>
            <div class="ps-v-row"><span class="ps-v-av" style="--c:#8a6418">₹</span><span class="ps-v-grow"><b>PF, ESI, PT and LWF ready</b><span>Registers and challans from this run</span></span></div>
        </div>
    </div>
    @include('website.visuals._float', ['pos' => 'right:-18px; bottom:-18px', 'icon' => 'heroicon-m-lock-closed', 'tone' => 'is-iris', 'title' => 'Finalised runs stay locked', 'sub' => 'Reopening is approved and recorded'])
</div>
