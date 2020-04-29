function isTabbedInterface () {
	return ($('.tab').length > 0);
}

function isHTMLSnapshot () {
	return ($('#html-view-source').length > 0);
}
function isNavTableInterface () {
	return ($('#meta-table').filter('.nav').find('a.browse').length > 0);
}
function activateTabFromLink (e) {
	e.preventDefault();
	
	var htmlId = e.target.hash;
	$('.tab').removeClass('current');
	$(e.target).addClass('current');
	$('section').fadeOut( { duration: 250 } ).promise().then( function () { $(htmlId).fadeIn( { duration: 250 } ); } );
}
function setupSnapshotTabLinks () {
	$('a.tab').click( activateTabFromLink );
}
function hideSnapshotTabs () {
	$('section').hide().promise().then( function () {
		var tab = $('.tab').eq(0).attr('href');
		$('.tab').eq(0).addClass('current');
		$(tab).show();
	});
}
function filterNavSectionFromLink (e) {
	
	let linkClasses = $(e.target).attr('class').split(/\s+/);
	let activeTable = $('table.nav').filter("." + linkClasses[1]);
	
	if (activeTable.length > 0) {
		e.preventDefault();
		
		$('#view-nav-table').find('table.nav').fadeOut({
			duration: 250
		}).promise().then( function () {
			$('a.browse').removeClass('current').promise().then( function () { $(e.target).addClass('current'); } );
			$(activeTable).fadeIn({
				duration: 250,
			}).promise().then( function () {
				$('html, body').animate({
					scrollTop: $(activeTable).offset().top
				}, 500 /*ms*/);
			});
		});
	}
}
function filterNavMainFromTagLink (e) {
	
	let path = e.target.pathname;
	let dirs = path.trim('/').replace(/^\/+/, '').split(/\/+/);
	
	if (dirs.length > 2 && dirs[0]=='browse' && dirs[1]=='tag') {
		let activeTag = dirs[2];
		
		if ($('tr.tagged-'+activeTag).length > 0) {
			e.preventDefault();
			
			$('tr.tagged-html, tr.tagged-data').hide().promise().then( function () {
				$('tr.tagged-'+activeTag).fadeIn({ duration: 250 /*ms*/ });
			});
		
			$('a.view-tag').removeClass('current').promise().then(function () {
				$(e.target).addClass('current');
			});
		}
	}
}
function setupNavTableTabLinks() {
	$('#meta-table').filter('.nav').find('a.browse').click( filterNavSectionFromLink );
	if ($('#view-nav-table').find('table.nav').length > 1) {
		$('#view-nav-table').find('table.nav').hide();
	}
	
	$('#view-tags').find('a.view-tag').click( filterNavMainFromTagLink );
	if ($('#view-tags').find('a.view-tag').length > 0) {
		$('#view-tags').find('a.view-tag').eq(0).click();
	}
	
}

var myArchiveUrl, myArchiveUrlHost;
function activateArchiveBookmarkletFromLink (e) {
	let link=e.currentTarget;
	let service = link.hostname;
	let services = {
	"*": function (url) {
		for (var service in services) {
			if (service != myArchiveUrlHost && service != '*') {
				services[service](url);
			} /* if */
		} /* for */
	},
	"archive.today": function (url) { window.open('https://archive.today?run=1&url='+url); },
	"archive.org": function (url) { window.open('https://web.archive.org/save/'+decodeURIComponent(url)) }
	};
	services[myArchiveUrlHost] = services['*'];

	if (typeof(services[service]) != 'undefined') {
		e.preventDefault();

		let url=link.href;
		if (link.hash.length > 0) {
			url=link.hash.slice(1);
		} else {
			url = link.href.replace(myArchiveUrl, "").replace(/^\/+/, '');
			url = decodeURIComponent(url);
			url = decodeURIComponent(url);
		}
		
		if ( url.length == 0 ) {
			url=window.location.href;
		}
		console.log(service, url);
		services[service](url);
	} /* if */
}
	
$(document).ready( function () {
	if (isTabbedInterface()) {
		setupSnapshotTabLinks();
		hideSnapshotTabs();
	} else if (isNavTableInterface()) {
		setupNavTableTabLinks();
	} /* if */

	$('.archive-service').click( activateArchiveBookmarkletFromLink );

	$('link[rel=archive-request-url]').each( function () {
		let a = document.createElement('a');
		a.href = this.href;
		myArchiveUrl = a.href;
		myArchiveUrlHost = a.hostname;
		console.log("myArchiveUrl=", myArchiveUrl, "myArchiveUrlHost=", myArchiveUrlHost);
	});
});
