$( document ).ready( function() {

    // сохранение настроек
	$( "#savecfg" ).on( "click", function() {
		forums = getForums();
		tor_clients = getTorClients();
		$data = $( "#config" ).serialize();
		$.ajax({
            context: this,
			type: "POST",
			url: "php/actions/set_config.php",
			data: { cfg:$data, forums:forums, tor_clients:tor_clients },
			beforeSend: function() {
				$( this ).prop( "disabled", true );
			},
			success: function( response ) {
				$( "#log" ).append( response );
			},
			complete: function() {
				$( this ).prop( "disabled", false );
			},
		});
	});
	
	// получение статистики
	$( "#get_statistics" ).on( "click", function () {
		// список подразделов
		forum_ids = getForumIds();
		$.ajax({
			context: this,
			type: "POST",
			url: "php/actions/get_statistics.php",
			data: { forum_ids:forum_ids },
			beforeSend: function() {
				$( this ).prop( "disabled", true );
			},
			success: function( response ) {
				json = $.parseJSON( response );
				$( "#table_statistics tbody" ).html( json.tbody );
				$( "#table_statistics tfoot" ).html( json.tfoot );
			},
			complete: function() {
				$( this ).prop( "disabled", false );
			}
		});
	});
	
	// получение отчёта
	$( "#get_reports" ).click( function() {
		var forum_id = ''; // из выпадающего списка
		$.ajax({
			type: "POST",
			url: "php/actions/get_reports.php",
			data: { forum_id:forum_id },
			beforeSend: function() {
				block_actions();
				$( "#process" ).text( "Формирование отчётов..." );
			},
			success: function( response ) {
				var response = $.parseJSON( response );
				$( "#log" ).append( response.log );
				$( "#reports" ).html( response.report );
				//инициализация "аккордиона" сообщений
				$( "div.report_message" ).each( function() {
					$( this ).accordion({
						collapsible: true,
						heightStyle: "content"
					});
				});
				// выделение тела сообщения двойным кликом
				$( "div.ui-accordion-content" ).dblclick( function() {
					var e = this; 
					if ( window.getSelection ) {
						var s = window.getSelection();
						if ( s.setBaseAndExtent ) {
							s.setBaseAndExtent( e, 0, e, e.childNodes.length );
						} else {
							var r = document.createRange();
							r.selectNodeContents( e );
							s.removeAllRanges();
							s.addRange( r );
						}
					} else if ( document.getSelection ) {
						var s = document.getSelection();
						var r = document.createRange();
						r.selectNodeContents( e );
						s.removeAllRanges();
						s.addRange( r );
					} else if ( document.selection ) {
						var r = document.body.createTextRange();
						r.moveToElementText( e );
						r.select();
					}
				});
			},
			complete: function() {
				block_actions();
			},
		});
	});
	
	// отправка отчётов
	$( "#send_reports" ).click( function() {
		$.ajax({
			type: "POST",
			url: "php/actions/send_reports.php",
			beforeSend: function() {
				block_actions();
				$( "#process" ).text( "Отправка отчётов на форум..." );
			},
			success: function( response ) {
				$( "#log" ).append( response );
			},
			complete: function() {
				block_actions();
			},
		});
	});
	
	// обновление сведений о раздачах
	$( "#update_info" ).click( function() {
		// список торрент-клиентов
		tor_clients = getTorClients();
		// список подразделов
		forums = getForums();
		forum_ids = getForumIds();
		$data = $( "#config" ).serialize();
		$.ajax({
			type: "POST",
			url: "php/actions/update_info.php",
			data: { cfg:$data, forums:forums, forum_ids:forum_ids, tor_clients:tor_clients },
			beforeSend: function() {
				block_actions();
				$( "#process" ).text( "Обновление сведений о раздачах..." );
			},
			success: function( response ) {
				response = $.parseJSON( response );
				$( "#log" ).append( response.log );
				if ( response.result.length ) {
					$( "#topics_result" ).text( response.result );
				}
				getFilteredTopics();
			},
			complete: function() {
				block_actions();
			},
		});
	});

});
