// Shows the right download. The server guesses the OS from the User-Agent; here we tell an Apple silicon Mac
// from an Intel one, which the User-Agent can't (every Mac browser says "Intel Mac OS X").
(function () {
  var section = document.getElementById('download');
  if (!section) return;

  function show(platform, macUnsure) {
    var found = false;
    section.querySelectorAll('.dl').forEach(function (el) {
      var on = el.dataset.for === platform;
      el.hidden = !on;
      found = found || on;
    });
    section.querySelector('.unsupported').hidden = found;
    section.querySelector('.mac-note').hidden = !macUnsure;
  }

  function webglRenderer() {
    try {
      var gl = document.createElement('canvas').getContext('webgl');
      var ext = gl && gl.getExtension('WEBGL_debug_renderer_info');
      return gl ? String(gl.getParameter(ext ? ext.UNMASKED_RENDERER_WEBGL : gl.RENDERER)) : '';
    } catch (e) {
      return '';
    }
  }

  // 'arm', 'x86' or '' when the browser won't say (Safari reports every Mac GPU as "Apple GPU").
  function macArch() {
    var hint = navigator.userAgentData && navigator.userAgentData.getHighEntropyValues
      ? navigator.userAgentData.getHighEntropyValues(['architecture']).then(function (v) { return v.architecture || ''; }, function () { return ''; })
      : Promise.resolve('');
    return hint.then(function (arch) {
      if (arch === 'arm') return 'arm';
      if (arch === 'x86') return 'x86';
      var r = webglRenderer();
      if (/Apple M\d/i.test(r)) return 'arm';
      if (/Intel|AMD|Radeon|NVIDIA/i.test(r)) return 'x86';
      return '';
    });
  }

  if (section.dataset.platform === 'macos') {
    macArch().then(function (arch) {
      if (arch === 'x86') show('intel-mac', false);
      else show('macos', arch === '');
    });
  }

  document.getElementById('show-all').addEventListener('click', function (e) {
    e.preventDefault();
    section.querySelectorAll('.dl').forEach(function (el) { el.hidden = false; });
    section.querySelector('.unsupported').hidden = true;
    section.querySelector('.mac-note').hidden = true;
    e.target.hidden = true;
  });

  document.querySelectorAll('button.copy').forEach(function (b) {
    b.addEventListener('click', function () {
      var link = location.origin + location.pathname + '?code=' + encodeURIComponent(b.dataset.code);
      navigator.clipboard.writeText(link).then(function () {
        b.textContent = 'copied';
        setTimeout(function () { b.textContent = 'copy link'; }, 1500);
      }, function () {
        window.prompt('Copy this link:', link);
      });
    });
  });
})();
