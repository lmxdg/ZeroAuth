
(function () {
    'use strict';


    var ann = document.getElementById('v1Announce');
    if (ann) {
        var permKey = 'v1_announce_closed';
        var closedAt = '';
        try { closedAt = localStorage.getItem(permKey) || ''; } catch (e) { }

        var today = new Date().toDateString();
        if (closedAt === today) {
            ann.style.display = 'none';
        }
        var tempBtn = document.getElementById('announceTempClose');
        var permBtn = document.getElementById('announcePermClose');
        if (tempBtn) {
            tempBtn.addEventListener('click', function () { ann.style.display = 'none'; });
        }
        if (permBtn) {
            permBtn.addEventListener('click', function () {
                ann.style.display = 'none';
                try { localStorage.setItem(permKey, today); } catch (e) { }
            });
        }
    }


    var car = document.getElementById('v1Carousel');
    var track = document.getElementById('v1CarTrack');
    if (!car || !track) return;

    var slides = track.children.length;
    if (slides < 1) return;

    var dotsBox = document.getElementById('v1CarDots');
    var dots = dotsBox ? dotsBox.children : [];
    var counter = document.getElementById('v1CarCounter');
    var prevBtn = document.getElementById('v1CarPrev');
    var nextBtn = document.getElementById('v1CarNext');
    var cur = 0;
    var timer = null;
    var interval = parseInt(car.getAttribute('data-autoplay'), 10) || 0;

    function pad(n) { return (n < 10 ? '0' : '') + n; }

    function render() {
        track.style.transform = 'translateX(-' + (cur * 100) + '%)';
        for (var k = 0; k < dots.length; k++) {
            dots[k].classList.toggle('on', k === cur);
        }
        if (counter) {
            counter.textContent = pad(cur + 1) + ' / ' + pad(slides);
        }
    }

    function go(i) {
        cur = (i + slides) % slides;
        render();
    }

    function play() {
        stop();
        if (interval > 0 && slides > 1) {
            timer = setInterval(function () { go(cur + 1); }, interval);
        }
    }

    function stop() {
        if (timer) { clearInterval(timer); timer = null; }
    }

    for (var d = 0; d < dots.length; d++) {
        (function (idx) {
            dots[idx].addEventListener('click', function () { go(idx); play(); });
        })(d);
    }

    if (prevBtn) {
        prevBtn.addEventListener('click', function () { go(cur - 1); play(); });
    }
    if (nextBtn) {
        nextBtn.addEventListener('click', function () { go(cur + 1); play(); });
    }


    var startX = 0;
    var deltaX = 0;
    var dragging = false;
    var moved = false;

    function dragStart(x) {
        dragging = true;
        moved = false;
        startX = x;
        deltaX = 0;
        stop();
        track.classList.add('dragging');
    }

    function dragMove(x) {
        if (!dragging) return;
        deltaX = x - startX;
        if (Math.abs(deltaX) > 8) {
            moved = true;
        }
        track.style.transform = 'translateX(calc(-' + (cur * 100) + '% + ' + deltaX + 'px))';
    }

    function dragEnd() {
        if (!dragging) return;
        dragging = false;
        track.classList.remove('dragging');
        if (Math.abs(deltaX) > 60) {
            go(deltaX < 0 ? cur + 1 : cur - 1);
        } else {
            go(cur);
        }
        play();
    }


    track.addEventListener('click', function (ev) {
        if (moved) {
            ev.preventDefault();
            ev.stopPropagation();
            moved = false;
        }
    }, true);

    car.addEventListener('mousedown', function (ev) { ev.preventDefault(); dragStart(ev.clientX); });
    window.addEventListener('mousemove', function (ev) { dragMove(ev.clientX); });
    window.addEventListener('mouseup', dragEnd);

    car.addEventListener('touchstart', function (ev) { dragStart(ev.touches[0].clientX); }, { passive: true });
    car.addEventListener('touchmove', function (ev) { dragMove(ev.touches[0].clientX); }, { passive: true });
    car.addEventListener('touchend', dragEnd);
    car.addEventListener('touchcancel', dragEnd);


    car.addEventListener('mouseenter', stop);
    car.addEventListener('mouseleave', play);


    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            stop();
        } else {
            play();
        }
    });

    render();
    play();
})();
