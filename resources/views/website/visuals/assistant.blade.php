<div class="ps-vis">
    <div class="ps-win">
        <div class="ps-win-bar"><i></i><i></i><i></i><span></span></div>
        <div class="ps-win-main" style="padding:18px; gap:12px">
            <div style="display:flex;justify-content:space-between;align-items:center"><div class="ps-v-greet">Assistant<small>Answers from your PeopleOS records</small></div><span class="ps-v-pill is-iris">Sources shown</span></div>
            <div class="ps-chat is-me">How many days of earned leave do I have left this year?</div>
            <div class="ps-chat">
                You have <b>12.5 days</b> of earned leave. 3 days are booked for 18–20 Dec, which would leave <b>9.5 days</b>.
                <span class="ps-chat-sources"><span class="ps-hash">Leave ledger · 2026</span><span class="ps-hash">Request #4821</span></span>
            </div>
            <div class="ps-chat is-me">Who in my team is away next week?</div>
            <div class="ps-chat">Two people: <b>Meera</b> (Mon–Tue, approved) and <b>Kabir</b> (Fri, pending your approval). <span class="ps-v-btn" style="margin-top:6px">Open approval</span></div>
        </div>
    </div>
    @include('website.visuals._float', ['pos' => 'right:-16px; bottom:-14px', 'icon' => 'heroicon-m-lock-closed', 'tone' => 'is-iris', 'title' => 'Secrets never leave', 'sub' => 'Classified before anything is sent'])
</div>
