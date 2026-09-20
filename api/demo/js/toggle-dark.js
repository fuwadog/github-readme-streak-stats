/**
 * Set a cookie
 * @param {string} cname - cookie name
 * @param {string} cvalue - cookie value
 * @param {number} exdays - number of days to expire
 */
function setCookie(cname, cvalue, exdays) {
  const d = new Date();
  d.setTime(d.getTime() + exdays * 24 * 60 * 60 * 1000);
  const expires = `expires=${d.toUTCString()}`;
  document.cookie = `${cname}=${cvalue}; ${expires}; path=/`;
}

/**
 * Get a cookie
 * @param {string} cname - cookie name
 * @returns {string} the cookie's value
 */
function getCookie(name) {
  const dc = document.cookie;
  const prefix = `${name}=`;
  let begin = dc.indexOf(`; ${prefix}`);
  /** @type {Number?} */
  let end = null;
  if (begin === -1) {
    begin = dc.indexOf(prefix);
    if (begin !== 0) return null;
  } else {
    begin += 2;
    end = document.cookie.indexOf(";", begin);
    if (end === -1) {
      end = dc.length;
    }
  }
  return decodeURI(dc.substring(begin + prefix.length, end));
}

/**
 * Turn on dark mode
 */
function darkmode() {
  document.querySelector("#darkmode-icon").innerText = "🌞";
  setCookie("darkmode", "on", 9999);
  document.body.setAttribute("data-theme", "dark");
  updateThemeControl(true);
}

/**
 * Turn on light mode
 */
function lightmode() {
  document.querySelector("#darkmode-icon").innerText = "🌙";
  setCookie("darkmode", "off", 9999);
  document.body.removeAttribute("data-theme");
  updateThemeControl(false);
}

function updateThemeControl(isDark) {
  const control = document.querySelector(".darkmode");
  if (!control) return;
  control.setAttribute("aria-pressed", String(isDark));
  control.setAttribute("aria-label", isDark ? "Switch to light mode" : "Switch to dark mode");
  control.title = isDark ? "Switch to light mode" : "Switch to dark mode";
}

/**
 * Toggle theme between light and dark
 */
function toggleTheme() {
  if (document.body.getAttribute("data-theme") !== "dark") {
    /* dark mode on */
    darkmode();
  } else {
    /* dark mode off */
    lightmode();
  }
}

// set the theme based on the cookie
if (getCookie("darkmode") === null && window.matchMedia("(prefers-color-scheme: dark)").matches) {
  darkmode();
}

document.querySelector(".darkmode")?.addEventListener("click", toggleTheme);
