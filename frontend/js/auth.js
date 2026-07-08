/**
 * auth.js
 * Handles login, signup, logout, and JWT token management.
 * All API calls go to backend/auth/*.php
 */

// const AUTH_API = "../backend/auth";
const AUTH_API = "https://smartstudy-backend-oekm.onrender.com/backend/auth";
// const AUTH_API = "http://localhost:8000/backend/auth";

const TOKEN_KEY = "ssc_token";
const USER_KEY  = "ssc_user";

// ─── Token Helpers ────────────────────────────────────────────────────────────

function saveToken(token) {
  localStorage.setItem(TOKEN_KEY, token);
}

function getToken() {
  return localStorage.getItem(TOKEN_KEY);
}

function removeToken() {
  localStorage.removeItem(TOKEN_KEY);
  localStorage.removeItem(USER_KEY);
}

function saveUser(user) {
  localStorage.setItem(USER_KEY, JSON.stringify(user));
}

function getUser() {
  try {
    return JSON.parse(localStorage.getItem(USER_KEY));
  } catch {
    return null;
  }
}

/**
 * Returns true if a valid (non-expired) JWT token exists in localStorage.
 */
// function isLoggedIn() {
//   const token = getToken();
//   if (!token) return false;

//   try {
//     // Decode JWT payload (middle part) — no verification, just expiry check
//     const payload = JSON.parse(atob(token.split(".")[1]));
//     const now = Math.floor(Date.now() / 1000);
//     return payload.exp > now;
//   } catch {
//     return false;
//   }
// }




function isLoggedIn() {
  const token = getToken();

  // console.log("TOKEN:", token);

  if (!token || token === "undefined" || token === "null") {
    return false;
  }

  try {
    const parts = token.split(".");

    if (parts.length !== 3) {
      return false;
    }

    const payload = JSON.parse(atob(parts[1]));

    // console.log("PAYLOAD:", payload);

    const now = Math.floor(Date.now() / 1000);

    return payload.exp && payload.exp > now;

  } catch (err) {
    console.error("JWT Parse Error:", err);
    return false;
  }
} 




/**
 * Redirects to login.html if the user is not authenticated.
 * Call this at the top of every protected page.
 */
function requireAuth() {
  if (!isLoggedIn()) {
    // window.location.href = "../frontend/pages/login.html";
      //  window.location.href = "http://localhost:8000/SmartStudyCompanion/frontend/pages/login.html";
          window.location.href = "../pages/login.html";
  }
}

/**
 * Redirects to dashboard.html if the user is already logged in.
 * Call this on login.html so logged-in users skip the login page.
 */
function redirectIfLoggedIn() {
  if (isLoggedIn()) {
    // window.location.href = "dashboard.html";
    // window.location.href = "http://localhost:8000/SmartStudyCompanion/frontend/pages/dashboard.html";
        window.location.href = "../pages/dashboard.html";
  }
}

// ─── API Calls ────────────────────────────────────────────────────────────────

/**
 * Sends login credentials to the backend.
 * On success, saves the JWT token and user info, then redirects to dashboard.
 *
 * @param {string} email
 * @param {string} password
 * @returns {Promise<{success: boolean, message: string}>}
 */
async function login(email, password) {
  try {
    const res = await fetch(`${AUTH_API}/login.php`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ email, password }),
    });

    // console.log("Response status:", res.status);
    const data = await res.json();
    // console.log("Response data:", data);

    if (data.success) {
      saveToken(data.token);
      saveUser(data.user);
      // console.log("Saved Token:", localStorage.getItem(TOKEN_KEY));
      return { success: true, message: "Login successful." };
    } else {
      return { success: false, message: data.message || "Invalid credentials." };
    }
  } catch (err) {
    console.error("Login error:", err);
    return { success: false, message: "Network error. Please try again." };
  }
}

/**
 * Sends signup data to the backend.
 * On success, saves the JWT token and user info, then redirects to dashboard.
 *
 * @param {string} name
 * @param {string} email
 * @param {string} password
 * @returns {Promise<{success: boolean, message: string}>}
 */
async function signup(name, email, password) {
  try {
    const res = await fetch(`${AUTH_API}/signup.php`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ name, email, password }),
    });

    const data = await res.json();

    if (data.success) {
      saveToken(data.token);
      saveUser(data.user);
      
      return { success: true, message: "Account created successfully." };
    } else {
      return { success: false, message: data.message || "Signup failed." };
    }
  } catch (err) {
    console.error("Signup error:", err);
    return { success: false, message: "Network error. Please try again." };
  }
}

/**
 * Logs the user out: calls backend logout endpoint, clears local storage,
 * and redirects to the login page.
 */
async function logout() {
  try {
    await fetch(`${AUTH_API}/logout.php`, {
      method: "POST",
      headers: { Authorization: `Bearer ${getToken()}` },
    });
  } catch {
    // Even if the request fails, clear local state
  } finally {
    removeToken();
    // window.location.href = "../frontend/pages/login.html";
      //  window.location.href = "http://localhost:8000/SmartStudyCompanion/frontend/pages/login.html";
          window.location.href = "../pages/login.html";
  }
}

// ─── UI Binding ───────────────────────────────────────────────────────────────

/**
 * Binds the login form (#login-form) to the login() function.
 * Displays errors in #login-error.
 * Call this inside DOMContentLoaded on login.html.
 */
function bindLoginForm() {
  redirectIfLoggedIn();

  const form    = document.getElementById("login-form");
  const errorEl = document.getElementById("login-error");
  const btn     = document.getElementById("login-btn");

  if (!form) return;

  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    errorEl.textContent = "";
    btn.disabled = true;
    btn.textContent = "Logging in…";

    const email    = document.getElementById("email").value.trim();
    const password = document.getElementById("password").value;

    if (!email || !password) {
      errorEl.textContent = "Please fill in all fields.";
      btn.disabled = false;
      btn.textContent = "Login";
      return;
    }

    const result = await login(email, password);

    if (result.success) {
      // window.location.href = "dashboard.html";
        //  window.location.href = "http://localhost:8000/SmartStudyCompanion/frontend/pages/dashboard.html";
        window.location.href = "../pages/dashboard.html";
    } else {
      errorEl.textContent = result.message;
      btn.disabled = false;
      btn.textContent = "Login";
    }
  });
}

/**
 * Binds the signup form (#signup-form) to the signup() function.
 * Displays errors in #signup-error.
 * Call this inside DOMContentLoaded on login.html (signup tab).
 */
function bindSignupForm() {
  const form    = document.getElementById("signup-form");
  const errorEl = document.getElementById("signup-error");
  const btn     = document.getElementById("signup-btn");

  if (!form) return;

  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    errorEl.textContent = "";
    btn.disabled = true;
    btn.textContent = "Creating account…";

    const name     = document.getElementById("signup-name").value.trim();
    const email    = document.getElementById("signup-email").value.trim();
    const password = document.getElementById("signup-password").value;
    const confirm  = document.getElementById("signup-confirm").value;

    if (!name || !email || !password) {
      errorEl.textContent = "Please fill in all fields.";
      btn.disabled = false;
      btn.textContent = "Create Account";
      return;
    }

    if (password !== confirm) {
      errorEl.textContent = "Passwords do not match.";
      btn.disabled = false;
      btn.textContent = "Create Account";
      return;
    }

    if (password.length < 6) {
      errorEl.textContent = "Password must be at least 6 characters.";
      btn.disabled = false;
      btn.textContent = "Create Account";
      return;
    }

    const result = await signup(name, email, password);

    if (result.success) {
      // window.location.href = "dashboard.html";
      // window.location.href = "http://localhost:8000/SmartStudyCompanion/frontend/pages/dashboard.html";
      window.location.href = "../pages/dashboard.html";
    } else {
      errorEl.textContent = result.message;
      btn.disabled = false;
      btn.textContent = "Create Account";
    }
  });
}

/**
 * Renders the logged-in user's name wherever .user-name elements exist in the page.
 */
function renderUserInfo() {
  const user = getUser();
  if (!user) return;
  document.querySelectorAll(".user-name").forEach((el) => {
    el.textContent = user.name || user.email;
  });
}

/**
 * Binds all logout buttons (.logout-btn) to the logout() function.
 */
function bindLogoutButtons() {
  document.querySelectorAll(".logout-btn").forEach((btn) => {
    btn.addEventListener("click", logout);
  });
}

// ─── Export (works in both ES module and plain script contexts) ───────────────
// If using ES modules, uncomment the export line below.
// export { login, signup, logout, isLoggedIn, requireAuth, getToken, getUser,
//          bindLoginForm, bindSignupForm, bindLogoutButtons, renderUserInfo };