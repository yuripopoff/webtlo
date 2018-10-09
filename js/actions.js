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

	// проверка доступности форума и API
	$( "#check_mirrors_access" ).on( "click", function() {
		$( this ).attr( "disabled", true );
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

	// получение bt_key, api_key, user_id
	$( "#tracker_username, #tracker_password" ).on( "change", function() {
		if ( $( "#tracker_username" ).val() && $( "#tracker_password" ).val() ) {
			if ( ! $( "#bt_key" ).val() || ! $( "#api_key" ).val() || ! $( "#user_id" ).val() ) {
				$data = $( "#config" ).serialize();
				$.ajax({
					type: "POST",
					url: "php/get_user_details.php",
					data: { cfg:$data },
					success: function( response ) {
						var response = $.parseJSON( response );
						$( "#log" ).append( response.log );
						$( "#bt_key" ).val( response.bt_key );
						$( "#api_key" ).val( response.api_key );
						$( "#user_id" ).val( response.user_id );
					},
				});
			}
		}
	});

	// проверка закрывающего слеша
	$( "#savedir, #dir_torrents" ).on( "change", function() {
		var e = this;
		var val = $( e ).val();
		if ( $.isEmptyObject( val ) ) {
			return false;
		}
		var path = $( e ).val();
		var last_s = path.slice( -1 );
		if ( path.indexOf('/') + 1) {
			if ( last_s != '/' ) {
				new_path = path + '/';
			} else {
				new_path = path;
			}
		} else {
			if ( last_s != '\\' ) {
				new_path = path + '\\';
			} else {
				new_path = path;
			}
		}
		$( e ).val( new_path );
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

	// очистка лога
	$( "#clear_log" ).on( "click", function() {
		$( "#log" ).text( "" );
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
