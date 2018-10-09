//~ $(document).ready(function() {
	
	/* инициализация кнопок */
	$("#topics_control button, #savecfg, #get_statistics, #clear_log").button();
	$("#select, #control, #new-torrents, #filter").buttonset();
	$("#log_tabs").tabs();

	// период хранения средних сидов
	$("#avg_seeders_period, #filter_avg_seeders_period").spinner({
		min: 1,
		max: 30,
		mouseWheel: true
	});
	
	// дата релиза в настройках
	$("#rule_date_release").spinner({
		min: 0,
		mouseWheel: true
	});
	
	// фильтрация раздач, количество сидов
	$("#rule_topics, .filter_rule input[type=text]").spinner({
		min: 0,
		step: 0.5,
		mouseWheel: true
	});
	
	// дата релиза в фильтре
	$.datepicker.regional["ru"];
	$("#filter_date_release").datepicker({
		changeMonth: true,
		changeYear: true,
		showOn: "both",
		dateFormat: 'dd.mm.yy',
		maxDate: "now",
		buttonText: '<i class="fa fa-calendar" aria-hidden="true"></i>'
	})
	.datepicker("setDate", $("#filter_date_release").val())
	.css("width", 90)
	.datepicker("refresh");
	
	// регулировка раздач, количество пиров
	$("#peers").spinner({
		min: 1,
		mouseWheel: true
	});

	/* кнопка справки */
	$("#help").addClass("ui-button ui-state-default");
	$("#help").hover(function(){
		if($(this).hasClass("ui-state-hover"))
			$(this).removeClass("ui-state-hover");
		else
			$(this).addClass("ui-state-hover");
	});
	
	/* инициализация главного меню */
	var menutabs = $( "#menutabs" ).tabs({
		activate: function (event, ui) {
			Cookies.set('selected-tab', (ui.newTab.index() === 2 ? 0 : ui.newTab.index()));
		},
		active: Cookies.get('selected-tab'),
		disabled: [ 2 ]
	});
	menutabs.addClass( "ui-tabs-vertical ui-helper-clearfix" ).removeClass("ui-widget-content");
	$( "#menutabs li.menu" ).removeClass( "ui-corner-top" ).addClass( "ui-corner-left" );
	
	/* инициализация "аккордиона" для вкладки настройки */
	$("div.sub_settings").each(function() {
		$(this).accordion({
			collapsible: true,
			heightStyle: "content"
		});	
	});
	
//~ });

// проверка закрывающего слеша
$("#savedir, #dir_torrents").on("change", function() {
	if($(this).val() != '') {
		CheckSlash(this);
	}
});

// получение bt_key, api_key, user_id
$("#tracker_username, #tracker_password").on("change", function() {
	if( $("#tracker_username").val() && $("#tracker_password").val() ) {
		if( !$("#bt_key").val() || !$("#api_key").val() || !$("#user_id").val() ) {
			$data = $("#config").serialize();
			$.ajax({
				type: "POST",
				url: "php/get_user_details.php",
				data: { cfg:$data },
				//~ beforeSend: function() {
					//~ $(".user_details").prop("disabled", true);
				//~ },
				success: function(response) {
					var resp = eval("(" + response + ")");
					$("#log").append(resp.log);
					$("#bt_key").val(resp.bt_key);
					$("#api_key").val(resp.api_key);
					$("#user_id").val(resp.user_id);
				},
				//~ complete: function() {
					//~ $(".user_details").prop("disabled", false);
				//~ },
			});
		}
	}
});

// проверка доступности форума и API
$( "#check_mirrors_access" ).on( "click", function() {
	$(this).attr( "disabled", true );
	var check_list = [ 'forum_url', 'api_url' ];
	var check_count = check_list.length;
	var result_list = [ 'text-danger', 'text-success' ];
	var $data = $( "#config" ).serialize();
	$.each( check_list, function( index, value ) {
		var element = "#" + value;
		var url = $( element ).val();
		if ( typeof url === "undefined" || $.isEmptyObject( url ) ) {
			check_count--;
			if ( check_count == 0 ) {
				$( "#check_mirrors_access" ).attr( "disabled", false );
			}
			$( element ).siblings( "i" ).removeAttr( "class" );
			return true;
		}
		$.ajax({
			type: "POST",
			url: "php/actions/check_mirror_access.php",
			data: { cfg:$data, url:url, url_type:value },
			success: function( response ) {
				$( element ).siblings( "i" ).removeAttr( "class" );
				var result = result_list[ response ];
				if ( typeof result !== "undefined" ) {
					$( element ).siblings( "i" ).addClass( "fa fa-circle " + result );
				}
			},
			beforeSend: function() {
				$( element ).siblings( "i" ).removeAttr( "class" );
				$( element ).siblings( "i" ).addClass( "fa fa-spinner fa-spin" );
			},
			complete: function() {
				check_count--;
				if ( check_count == 0 ) {
					$( "#check_mirrors_access" ).attr( "disabled", false );
				}
			}
		});
	});
});

// очистка лога
$( "#clear_log" ).on( "click", function() {
	$("#log").text("");
});

// чтение лога из файла
$( "#log_tabs" ).on( "tabsactivate", function( event, ui ) {
	// current tab
	var element_new = $( ui.newTab ).children( "a" );
	var name_new = $( element_new ).text();
	if ( ! element_new.hasClass( "log_file" ) ) {
		return true;
	}
	// previous tab
	var element_old = $( ui.oldTab ).children( "a" );
	var name_old = $( element_old ).text();
	if ( element_old.hasClass( "log_file" ) ) {
		$( "#log_" + name_old ).text( "" );
	}
	// request
	$.ajax({
		type: "POST",
		url: "php/actions/get_log_content.php",
		data: { log_file: name_new },
		success: function( response ) {
			if ( typeof response !== "undefined" ) {
				$( "#log_" + name_new ).html( response );
			}
		},
		beforeSend: function() {
			$( "#log_" + name_new ).html( "<i class=\"fa fa-spinner fa-pulse\"></i>" );
		}
	});
});
