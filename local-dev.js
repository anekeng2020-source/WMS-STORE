/* ═══ LOCAL DEVELOPMENT ONLY — DO NOT UPLOAD THIS FILE TO THE SERVER ═══
   index.html loads it only when the page is opened on localhost / 127.0.0.1. With it the web app runs in
   local mode (data kept in this browser, the real server is not touched) and signs in with the account below.
   The password is not stored here — only a PBKDF2-SHA256 hash of it. Delete this file to turn local mode off. */
window.LOCAL_DEV={
  users:[
    {username:"superadmin", fullName:"Super Administrator", department:"HQ", position:"Super Admin", company:"*",
     passHash:{alg:"PBKDF2-SHA256", iter:150000, salt:"zNNcXj69/ImPzY9J2bywcw==", hash:"uOpXK2E4gBDzHeqMEsz2kpPwAQXx2a+Q7+lXhtX6rgU="}}
  ]
};
