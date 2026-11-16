/// Which app is talking to the API. Sent as the `app` role hint on sign-in; the backend only
/// accepts users holding a matching role and scopes the token to this app.
enum ClientApp {
  customer('customer'),
  vendor('vendor'),
  rider('rider');

  const ClientApp(this.apiValue);

  final String apiValue;
}
