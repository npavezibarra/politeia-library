(function($){
    $(function(){
      const $start = $('#prs-start-btn');
      const $stop  = $('#prs-stop-btn');
      const $disp  = $('#prs-timer-display');
      const $endFields = $('#prs-end-fields');
  
      const $startTime = $('#prs_start_time');
      const $endTime   = $('#prs_end_time');
      const $elapsed   = $('#prs_elapsed');
  
      let startMs = null;
      let interval = null;
  
      function fmt(ms){
        const s = Math.floor(ms/1000);
        const hh = String(Math.floor(s/3600)).padStart(2,'0');
        const mm = String(Math.floor((s%3600)/60)).padStart(2,'0');
        const ss = String(s%60).padStart(2,'0');
        return `${hh}:${mm}:${ss}`;
      }
  
      function nowISO(){
        // local time in ISO-like string (server will convert to UTC/GMT)
        const d = new Date();
        const pad = n => String(n).padStart(2,'0');
        return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
      }
  
      $start.on('click', function(){
        if (!$('#prs_start_page').val()) { alert('Enter start page'); return; }
        startMs = Date.now();
        $startTime.val(nowISO());
        $start.prop('disabled', true);
        $stop.prop('disabled', false);
        interval = setInterval(function(){
          $disp.text(fmt(Date.now() - startMs));
        }, 1000);
      });
  
      $stop.on('click', function(){
        if (!startMs) return;
        clearInterval(interval);
        $disp.text(fmt(Date.now() - startMs));
        $endFields.show();
        $stop.prop('disabled', true);
        $endTime.val(nowISO());
        $elapsed.val(String(Date.now() - startMs));
        $('#prs_end_page').attr('required', true).focus();
      });
    });
  })(jQuery);
  