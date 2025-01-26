function emailSend() {
    // Get values from the form fields when the email is being sent
    var userName = document.getElementById('name').value;
    var email = document.getElementById('email').value;
    var message = document.getElementById('message').value;

    // Create the message body
    var messageBody = "Name: " + userName + 
        "<br/> Email: " + email + 
        "<br/> Message: " + message;

    // Send the email
    Email.send({
        Host: "smtp.mailendo.com",
        Username: "muhammadyazdan375@gmail.com",
        Password: "DE5A7D5A7DFAE4EE19140A06622FB309E89B",
        To: 'yazdanmuhammad30@gmail.com',
        From: "muhammadyazdan375@gmail.com",
        Subject: "I Want To Work With You",
        Body: messageBody
    }).then(
        message => alert(message)
    );
}
