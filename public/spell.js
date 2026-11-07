// The page's one piece of art: a ball of magic drawn the way cel-shaded animation draws effects. Hard-edged
// colour steps from a saturated rim to a white-hot core, a soft glow around it, and sharp flame tongues
// streaming one way. It "boils" between three hand-drawn frames at animation speed, not a smooth tween.
(function () {
  var host = document.querySelector('.spell');
  if (!host) return;
  var NS = 'http://www.w3.org/2000/svg';
  var still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var TRAIL = -Math.PI / 3; // the flames stream up and to the right

  function rng(seed) {
    return function () {
      seed = (seed * 16807) % 2147483647;
      return (seed - 1) / 2147483646;
    };
  }

  function node(name, attrs) {
    var e = document.createElementNS(NS, name);
    for (var k in attrs) e.setAttribute(k, attrs[k]);
    return e;
  }

  function turn(from, to) {
    var d = to - from;
    while (d > Math.PI) d -= Math.PI * 2;
    while (d < -Math.PI) d += Math.PI * 2;
    return d;
  }

  // A ball ringed with flame tongues: round valleys, sharp tips. Tongues facing the trail grow long and
  // the rest bend toward it, so the whole shape streams one way.
  function flame(r, cx, cy, radius, tongues, reach) {
    var pts = [], step = Math.PI * 2 / tongues, start = r() * step;
    for (var i = 0; i < tongues; i++) {
      var a = start + i * step + (r() - 0.5) * step * 0.25, mid = a + step / 2;
      var align = Math.max(0, Math.cos(mid - TRAIL));
      align *= align;
      var vr = radius * (0.9 + r() * 0.12);
      var tr = radius * (1 + reach * (0.1 + r() * 0.25 + align * (1.5 + r() * 1.1)));
      var ta = mid + turn(mid, TRAIL) * (0.3 + 0.35 * (1 - align)) * Math.min(1, tr / radius - 1);
      pts.push([cx + Math.cos(a) * vr, cy + Math.sin(a) * vr]);
      pts.push([cx + Math.cos(ta) * tr, cy + Math.sin(ta) * tr]);
    }
    var d = 'M' + pts[0][0].toFixed(1) + ' ' + pts[0][1].toFixed(1);
    for (var j = 0; j < pts.length; j += 2) {
      var v = pts[j], t = pts[j + 1], nv = pts[(j + 2) % pts.length];
      var c1 = [(v[0] + t[0]) / 2 + (t[1] - v[1]) * 0.22, (v[1] + t[1]) / 2 - (t[0] - v[0]) * 0.22];
      var c2 = [(t[0] + nv[0]) / 2 + (nv[1] - t[1]) * 0.12, (t[1] + nv[1]) / 2 - (nv[0] - t[0]) * 0.12];
      d += ' Q' + c1[0].toFixed(1) + ' ' + c1[1].toFixed(1) + ' ' + t[0].toFixed(1) + ' ' + t[1].toFixed(1);
      d += ' Q' + c2[0].toFixed(1) + ' ' + c2[1].toFixed(1) + ' ' + nv[0].toFixed(1) + ' ' + nv[1].toFixed(1);
    }
    return d + ' Z';
  }

  // Embers thrown off along the trail: sharp four-point glints.
  function embers(g, r, cx, cy) {
    for (var i = 0; i < 11; i++) {
      var a = TRAIL + (r() - 0.5) * 1.9, dist = 220 + r() * 190, s = 4 + r() * 9;
      var x = cx + Math.cos(a) * dist, y = cy + Math.sin(a) * dist;
      g.appendChild(node('path', {
        'class': i % 3 ? 'k2' : 'k3',
        d: 'M' + x + ' ' + (y - s * 1.6) + ' Q' + x + ' ' + y + ' ' + (x + s) + ' ' + y + ' Q' + x + ' ' + y + ' ' + x + ' ' + (y + s * 1.6) +
          ' Q' + x + ' ' + y + ' ' + (x - s) + ' ' + y + ' Q' + x + ' ' + y + ' ' + x + ' ' + (y - s * 1.6) + ' Z'
      }));
    }
  }

  var svg = node('svg', { viewBox: '0 0 800 800', preserveAspectRatio: 'xMidYMid meet' });
  var defs = node('defs', {});
  var glow = node('filter', { id: 'glow', x: '-50%', y: '-50%', width: '200%', height: '200%' });
  glow.appendChild(node('feGaussianBlur', { stdDeviation: '26' }));
  defs.appendChild(glow);
  svg.appendChild(defs);

  var cx = 360, cy = 470, frames = [];
  for (var f = 0; f < 3; f++) {
    var r = rng(4021 + f * 977);
    var g = node('g', { 'class': 'frame' });
    var outer = flame(r, cx, cy, 150, 12, 1.0);
    g.appendChild(node('path', { 'class': 'halo', d: outer, filter: 'url(#glow)' }));
    g.appendChild(node('path', { 'class': 'k1', d: outer }));
    g.appendChild(node('path', { 'class': 'k2', d: flame(r, cx - 6, cy + 6, 112, 10, 0.85) }));
    g.appendChild(node('path', { 'class': 'k3', d: flame(r, cx - 12, cy + 12, 70, 9, 0.6) }));
    embers(g, r, cx, cy);
    svg.appendChild(g);
    frames.push(g);
  }
  host.appendChild(svg);

  var i = 0;
  frames[0].classList.add('on');
  if (still) return;
  setInterval(function () {
    frames[i].classList.remove('on');
    i = (i + 1) % frames.length;
    frames[i].classList.add('on');
  }, 125); // 8 drawings a second, "on twos" as hand-drawn animation does
})();
