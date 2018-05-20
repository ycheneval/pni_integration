/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

var _datetime = null;
var _quotas = null;

/*if( typeof console.log == 'undefined' ){
  var console = {log:function(s){}};
}*/

$(function(){
  $(document).on('change', '.staff-booking-create form input', function(){
    var form = $(".staff-booking-create form");
    form.trigger('timesavailability');
  });
  
  $(document).on('change', '.staff-booking-create form select[name=host_sfid],.staff-booking-create form select[name=guest_sfid]', function(){
    var form = $(".staff-booking-create form");
    if( form.find('select[name=guest_sfid]').val() != null && form.find('select[name=host_sfid]').val() != null ) {
      form.trigger('timesavailability');
    }
  });

  $(document).on('timesavailability', '.staff-booking-create form', function(){
    //console.log('timesavailability');    
    // Keeping selected value so that if can be selected again if found
    var selected_date = $(this).find('select[name=date] :selected').val();
    var selected_datetime = $(this).find('select[name=datetime] :selected').val();
    $(this).find('select[name=date]').prop('disabled', false);
    $(this).find('select[name=datetime]').prop('disabled', false);
    $(this).find('select[name=room]').prop('disabled', false);
    $(this).find('button[type=button]').prop('disabled', false);
    
//    if( $(this).find('select[name=guest_sfid]').val() != null && $(this).find('select[name=host_sfid]').val() != null ){
        $.ajax('./load-times-availability', {     
          data: prepare_form_for_get($(this), ['textarea[name=context]','textarea[name=description]']),
          context: $(this),
          beforeSend: function(){
            $(this).find('select[name=date]').html('<option>Loading...</option>');
            $(this).find('select[name=datetime]').html('<option>Available times</option>');
          },
          success:function(data){
            _datetime = data['slots'];
            _quotas = data['quotas'];
            var _return = '<option value="">Select a date</option>';
            for (var date in data['slots']) {
              if (date === selected_date) {
                _return += '<option value="' + date + '" selected>' + date + '</option>';
              }
              else {
                _return += '<option value="' + date + '">' + date + '</option>';
              }
            }
            $(this).find('select[name=date]').html(_return);
            if (_quotas['display']) {
                if (_quotas['count'] >= _quotas['limit']) {
                    $(this).find('#quota_text').attr('style', 'color: #F16D2A;');
                }
                $(this).find('#quota_text').html(_quotas['display_text']);
            }
            else {
                $(this).find('#quota_text').removeAttr('style');
                $(this).find('#quota_text').html("N/A");
            }
            $(this).find('select[name=date]').prop('disabled', false);
            if (typeof selected_date !== 'undefined') {
              datetime_setup(selected_datetime);
            }
          }
        });
//    }
  });
  
  $(document).on('change', '.staff-booking-create form select[name=event_sfid]', function(){
    var form = $(this).parents('form:first');
    form.trigger('draw-person-picker');
    form.find('select[name=guest_sfid]').val('');
    form.find('select[name=host_sfid]').val('');
    $.ajax('./default-duration', {
      data: prepare_form_for_get($(this), ['textarea[name=context]','textarea[name=description]']),
      context: $(this),
      beforeSend: function(){
        $(this).find('select[name=date]').html('<option>Loading...</option>');
        $(this).find('select[name=datetime]').html('<option>Available times</option>');
      },
      success:function(data){
        var _duration = data._duration;
        if ('15m' == _duration) {
          $('#duration15m').removeClass('hide');
          $('#duration30m').removeClass('hide');
          $('#duration20m').addClass('hide');
          $("#duration1").prop("checked", true);
        }
        else {
          $('#duration15m').addClass('hide');
          $('#duration30m').addClass('hide');
          $('#duration20m').removeClass('hide');
          $("#duration3").prop("checked", true);
        }
      }
    });
    form.trigger('timesavailability');

  });

  $(document).on('change', '.staff-booking-create form select[name=date]', function() {
    var form = $(this).parents('form:first');
    var selected_datetime = form.find('select[name=datetime] :selected').val();
    datetime_setup(selected_datetime);
  });
  
  $(document).on('change', '.staff-booking-create form select[name=datetime]', function(){
    $(".staff-booking-create form").trigger('roomsavailability');
  });
  
  $(document).on('roomsavailability', '.staff-booking-create form', function(){
    //console.log('load_roomsavailability'); 
//    $(this).find('select[name=room]').prop('disabled', true);
//    $(this).find('button[type=button]').prop('disabled', true);
    
    if ($(this).find('select[name=date]').val() !== null && 
        $(this).find('select[name=datetime]').val() !== null) {
        $.ajax('./load-rooms-availability', {     
          data: prepare_form_for_get($(this), ['textarea[name=context]','textarea[name=description]', 'select[name=date]', 'select[name=room]']),
          context: $(this),
          beforeSend: function(){
            $(this).find('select[name=room]').html('<option>Loading...</option>');
          },
          success:function(data){
            var selected_room = $(this).find('select[name=room] :selected').val();

            $(this).find('select[name=room]').html(data);
            if (selected_room !== null) {
              $(this).find('select[name=room]').val(selected_room);
            }
            $(this).find('select[name=room]').prop('disabled', false);
            $(this).find('button[type=button]').prop('disabled', false);
          }
        });
    }
  });
  
  $(document).on('click', '.staff-booking-create form button.submit', function(){
        var form = $(this).parents('form:first');
        var button = $(this);
        $.ajax('./book-new-bilateral', {     
          type: "POST",
          data : form.serialize(),
          context: $(this),
          beforeSend: function(){
            form.find("input, select").attr("disabled", true); 
            button.text('Please wait...');
            button.prop('disabled', true);
          },
          success:function(data){
            form.find("input, select").attr("disabled", false);
            $('.tbb-result .panel').removeClass('panel-info');
            if(typeof data.error != 'undefined'){
              button.text('Try again');
              $('.tbb-result .panel').addClass('panel-danger');
              $('.tbb-result .panel .panel-body p').text(data.error);
            }
            else if(typeof data.result.session != 'undefined'){
              button.text('Done');
              $('.tbb-result .panel').addClass('panel-success');
              $('.tbb-result .panel .panel-body p').html('<strong>' + (data.result.session.session_name?data.result.session.session_name:'A bilateral session') + '</strong> (' + data.result.session.session_sfid + ') has been successfully created in the room <strong>' + data.result.session.session_room + '</strong>. <a href="#" onclick="window.location.reload();">Click here</a> to create a new bilateral.');
            }
          },
          error:function(data) {
            button.text('Error!');
            form.find("input, select").attr("disabled", false);
            //console.log('Error found');
          }
        });
  });
  
  function datetime_setup(selected_datetime) {
    var form = $(".staff-booking-create form");
    var _date = form.find("select[name=date]").val();
    if (typeof _date !== 'undefined') {
      var _return = '';
      var datetime_selected = false;
      if( typeof _datetime[_date] !== 'undefined' ){
        // Try to preserve the existing value
        for (var value in _datetime[_date]) {
          if (value === selected_datetime) {
            _return += '<option value="' + value + '" selected>' + _datetime[_date][value] + '</option>';
            datetime_selected = true;
          }
          else {
            _return += '<option value="' + value + '">' + _datetime[_date][value] + '</option>';
          }
        }
        form.find('select[name=datetime]').html(_return);
        if (datetime_selected) {
          // Trigger room loading
          form.trigger('roomsavailability');
        }
      }
    }
  }

  function array_remove(arr, item) {
    var index;
    do {
      index = $.inArray(item, arr);
      if (-1 !== index) {
          arr.splice(index, 1);
      }
     } while (-1 !== index);
   return arr;
 }

  function prepare_form_for_get(form, to_disable) {
    // Disable some inputs first
    var index;
    for (index = 0; index < to_disable.length; ++index) {
        form.find(to_disable[index]).attr("disabled", true);
    }
    var fields = form.serialize();
    // Enable them again
    for (index = 0; index < to_disable.length; ++index) {
        form.find(to_disable[index]).attr("disabled", false);
    }
//    form.find('select[name=date]').attr("disabled", true);
//    form.find('select[name=room]').attr("disabled", true);
//    form.find('textarea[name=description]').attr("disabled", true);
//    form.find('textarea[name=context]').attr("disabled", true);
//    form.find('select[name=date]').attr("disabled", false);
//    form.find('select[name=room]').attr("disabled", false);
//    form.find('textarea[name=description]').attr("disabled", false);
//    form.find('textarea[name=context]').attr("disabled", false);
    return fields;
  }

  function formatRepo (repo) {
    if (repo.loading) return repo.text;

    var markup = '<div class="clearfix">' +
    '<div >' +
    '<div >' + repo.name + '</div>' +
    '</div>';
    markup += '</div></div>';

    return markup;
  }
  
  $(document).on('draw-person-picker', '.staff-booking-create form', function(){
    $(this).find("select.person-picker").select2({
      ajax: {
        url: "./search-participants",
        dataType: 'json',
        delay: 250,
        data: function (params) {
          var defaults = {
            q: params.term, // search term
            page: params.page,

          };
 
          var data = $('.staff-booking-create form').serializeArray();
          var key;
          for (var i in data){
            defaults[data[i].name] = data[i].value;
          }
          return defaults;
        },
        processResults: function (data, page) {
          // parse the results into the format expected by Select2.
          // since we are using custom formatting functions we do not need to
          // alter the remote JSON data
          return {
            results: data.items
          };
        },
        cache: true
      },
      escapeMarkup: function (markup) { return markup; }, // let our custom formatter work
      minimumInputLength: 3,
      templateResult: formatRepo, // omitted for brevity, see the source of this page
      templateSelection: function (repo) {return repo.name;}
    });
  });
  
  $(document).on('submit', 'form.retry', function(e){
    e.preventDefault();
    $.ajax('', {     
      data : $(this).serialize(),
      context: $(this),
      beforeSend: function(){
        $(this).find('input[type=submit]').attr('value', "Please wait...").attr("disabled", true);
      },
      success:function(data){
        if(data.error){
          $(this).find('input[type=submit]').attr('value', "Query failed");
        }
        else if(data.row._hc_lastop == 'SYNCED'){
          $(this).find('input[type=submit]').attr('value', "Synced!");
        }
        else if(data.row._hc_lastop == 'UPDATED'){
          $(this).find('input[type=submit]').attr('value', "Updated!");
        }
        else if(data.row._hc_lastop == 'PENDING'){
          $(this).find('input[type=submit]').attr('value', "Timeout!");
        }
        else {
          $(this).find('input[type=submit]').attr('value', "Fail!");
        }
        $(this).parent().next().text(data.query);
      }
    });
  });
  
  if( $('.staff-booking-create form select.event_sfid option').length == 2 ){
    $('.staff-booking-create form select.event_sfid').change();
  }
});