const AUTH_API = "https://smartstudy-backend-oekm.onrender.com/backend/auth";
const TOKEN_KEY = "ssc_token";
const USER_KEY  = "ssc_user";

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

function isLoggedIn() {
  const token = getToken();

  if (!token || token === "undefined" || token === "null") {
    return false;
  }

  try {
    const parts = token.split(".");

    if (parts.length !== 3) {
      return false;
    }

    const payload = JSON.parse(atob(parts[1]));


    const now = Math.floor(Date.now() / 1000);

    return payload.exp && payload.exp > now;

  } catch (err) {
    console.error("JWT Parse Error:", err);
    return false;
  }
} 

function requireAuth() {
  if (!isLoggedIn()) {
          window.location.href = "login.html";
  }
}

function redirectIfLoggedIn() {
  if (isLoggedIn()) {
        window.location.href = "dashboard.html";
  }
}

async function login(email, password) {
  try {
    const res = await fetch(`${AUTH_API}/login.php`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ email, password }),
    });

    const data = await res.json();

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

async function logout() {
  try {
    await fetch(`${AUTH_API}/logout.php`, {
      method: "POST",
      headers: { Authorization: `Bearer ${getToken()}` },
    });
  } catch {
  } finally {
    removeToken();
          window.location.href = "login.html";
  }
}

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
        window.location.href = "dashboard.html";
    } else {
      errorEl.textContent = result.message;
      btn.disabled = false;
      btn.textContent = "Login";
    }
  });
}

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
      window.location.href = "dashboard.html";
    } else {
      errorEl.textContent = result.message;
      btn.disabled = false;
      btn.textContent = "Create Account";
    }
  });
}

function renderUserInfo() {
  const user = getUser();
  if (!user) return;
  document.querySelectorAll(".user-name").forEach((el) => {
    el.textContent = user.name || user.email;
  });
}

function bindLogoutButtons() {
  document.querySelectorAll(".logout-btn").forEach((btn) => {
    btn.addEventListener("click", logout);
  });
}
