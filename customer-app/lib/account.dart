import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'customer_api.dart';
import 'ui.dart';
import 'delivery_pin.dart';
import 'recovery.dart';

class CustomerLogin extends StatefulWidget {
  const CustomerLogin({
    super.key,
    required this.api,
    this.onSignedIn,
    this.forCheckout = false,
  });
  final CustomerApi api;
  final bool forCheckout;
  final Future<void> Function()? onSignedIn;
  @override
  State<CustomerLogin> createState() => _CustomerLoginState();
}

class _CustomerLoginState extends State<CustomerLogin> {
  final email = TextEditingController(),
      password = TextEditingController(),
      name = TextEditingController(),
      phone = TextEditingController(),
      confirm = TextEditingController();
  final form = GlobalKey<FormState>();
  bool busy = false, registering = false, obscure = true;
  String? error;
  @override
  void dispose() {
    for (final c in [email, password, name, phone, confirm]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> submit() async {
    if (!form.currentState!.validate()) return;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      await widget.api.emailSignIn(
        email.text.trim(),
        password.text,
        registering
            ? {
                'name': name.text.trim(),
                'phone': phone.text.trim(),
                'password_confirmation': confirm.text,
              }
            : null,
      );
      if (!mounted) return;
      if (widget.onSignedIn != null) {
        await widget.onSignedIn!();
      } else {
        Navigator.pop(context, true);
      }
    } catch (e) {
      if (mounted) setState(() => error = friendlyError(e));
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(
      title: Text(registering ? 'Create account' : 'Welcome to Dropbell'),
    ),
    body: ShopBody(
      children: [
        const SizedBox(height: 24),
        const Center(child: DropbellLogo(width: 240)),
        const SizedBox(height: 28),
        Text(
          registering ? 'Make yourself at home.' : 'Welcome back.',
          style: Theme.of(context).textTheme.headlineLarge,
        ),
        const SizedBox(height: 10),
        Text(
          widget.forCheckout
              ? 'Sign in or create an account to complete your order. Your cart is saved.'
              : 'Sign in to see your orders, saved addresses and account.',
        ),
        const SizedBox(height: 24),
        Form(
          key: form,
          child: AutofillGroup(
            child: Column(
              children: [
                if (registering) ...[
                  TextFormField(
                    controller: name,
                    enabled: !busy,
                    decoration: const InputDecoration(labelText: 'Full name'),
                    validator: (v) =>
                        v!.trim().isEmpty ? 'Enter your name' : null,
                  ),
                  const SizedBox(height: 16),
                  TextFormField(
                    controller: phone,
                    enabled: !busy,
                    keyboardType: TextInputType.phone,
                    decoration: const InputDecoration(
                      labelText: 'Mobile number',
                    ),
                    validator: (v) => RegExp(r'^[6-9]\d{9}$').hasMatch(v ?? '')
                        ? null
                        : 'Enter a valid mobile number',
                  ),
                  const SizedBox(height: 16),
                ],
                TextFormField(
                  controller: email,
                  enabled: !busy,
                  keyboardType: TextInputType.emailAddress,
                  autofillHints: const [AutofillHints.email],
                  decoration: const InputDecoration(labelText: 'Email'),
                  validator: (v) =>
                      RegExp(
                        r'^[^\s@]+@[^\s@]+\.[^\s@]+$',
                      ).hasMatch(v?.trim() ?? '')
                      ? null
                      : 'Enter a valid email',
                ),
                const SizedBox(height: 16),
                TextFormField(
                  controller: password,
                  enabled: !busy,
                  obscureText: obscure,
                  autofillHints: [
                    registering
                        ? AutofillHints.newPassword
                        : AutofillHints.password,
                  ],
                  decoration: InputDecoration(
                    labelText: 'Password',
                    suffixIcon: IconButton(
                      onPressed: () => setState(() => obscure = !obscure),
                      icon: Icon(
                        obscure
                            ? Icons.visibility_outlined
                            : Icons.visibility_off_outlined,
                      ),
                    ),
                  ),
                  validator: (v) => v == null || v.isEmpty
                      ? 'Enter your password'
                      : registering && v.length < 8
                      ? 'Use at least 8 characters'
                      : null,
                ),
                if (registering) ...[
                  const SizedBox(height: 16),
                  TextFormField(
                    controller: confirm,
                    enabled: !busy,
                    obscureText: true,
                    decoration: const InputDecoration(
                      labelText: 'Confirm password',
                    ),
                    validator: (v) =>
                        v == password.text ? null : 'Passwords do not match',
                  ),
                ],
              ],
            ),
          ),
        ),
        if (error != null) ErrorBox(error!),
        const SizedBox(height: 24),
        FilledButton(
          onPressed: busy ? null : submit,
          child: Text(
            busy
                ? 'Please wait…'
                : registering
                ? 'Create account'
                : 'Sign in',
          ),
        ),
        if (!registering)
          TextButton(
            onPressed: busy
                ? null
                : () => Navigator.of(context).push(
                    MaterialPageRoute<void>(
                      builder: (_) => PasswordRecoveryPage(api: widget.api),
                    ),
                  ),
            child: const Text('Forgot password?'),
          ),
        TextButton(
          onPressed: busy
              ? null
              : () => setState(() {
                  registering = !registering;
                  error = null;
                }),
          child: Text(
            registering
                ? 'Already have an account? Sign in'
                : 'New customer? Create an account',
          ),
        ),
      ],
    ),
  );
}

class AddressBook extends StatefulWidget {
  const AddressBook({super.key, required this.api, required this.profile});
  final CustomerApi api;
  final CustomerProfile profile;
  @override
  State<AddressBook> createState() => _AddressBookState();
}

class _AddressBookState extends State<AddressBook> {
  late CustomerProfile profile = widget.profile;
  bool busy = false;
  String? error;
  Future<void> edit([SavedAddress? address]) async {
    final updated = await Navigator.of(context).push<CustomerProfile>(
      MaterialPageRoute(
        builder: (_) => AddressEditor(
          api: widget.api,
          address: address,
          phone: profile.phone,
        ),
      ),
    );
    if (mounted && updated != null) setState(() => profile = updated);
  }

  Future<void> remove(SavedAddress address) async {
    final yes = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Delete this address?'),
        content: Text(address.text),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Keep address'),
          ),
          TextButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Delete'),
          ),
        ],
      ),
    );
    if (yes != true || !mounted) return;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final updated = await widget.api.deleteAddress(address.id);
      if (mounted) setState(() => profile = updated);
    } catch (e) {
      if (mounted) setState(() => error = friendlyError(e));
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Saved addresses')),
    body: ShopBody(
      children: [
        if (busy) const LinearProgressIndicator(),
        if (error != null) ErrorBox(error!),
        if (profile.addresses.isEmpty)
          const ShopCard(
            child: Text('Add your first address so we know where to deliver.'),
          ),
        for (final address in profile.addresses)
          ShopCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        address.label,
                        style: Theme.of(context).textTheme.titleLarge,
                      ),
                    ),
                    if (address.json['isDefault'] == true)
                      const Chip(label: Text('Default')),
                  ],
                ),
                Text(address.json['name']),
                Text(address.text),
                Text('+91 ${address.json['phone']}'),
                const SizedBox(height: 8),
                Row(
                  children: [
                    TextButton(
                      onPressed: busy ? null : () => edit(address),
                      child: const Text('Edit'),
                    ),
                    TextButton(
                      onPressed: busy ? null : () => remove(address),
                      child: const Text('Delete'),
                    ),
                  ],
                ),
              ],
            ),
          ),
        const SizedBox(height: 16),
        FilledButton(
          onPressed: busy ? null : () => edit(),
          child: const Text('Add address'),
        ),
      ],
    ),
  );
}

class AddressEditor extends StatefulWidget {
  const AddressEditor({
    super.key,
    required this.api,
    required this.phone,
    this.address,
  });
  final CustomerApi api;
  final String phone;
  final SavedAddress? address;
  @override
  State<AddressEditor> createState() => _AddressEditorState();
}

class _AddressEditorState extends State<AddressEditor> {
  final form = GlobalKey<FormState>();
  late final fields = {
    for (final name in ['name', 'phone', 'street'])
      name: TextEditingController(
        text:
            widget.address?.json[name] ?? (name == 'phone' ? widget.phone : ''),
      ),
  };
  late String label = widget.address?.label ?? 'Home';
  late bool isDefault = widget.address?.json['isDefault'] == true;
  bool busy = false;
  String? error;
  late Map<String, dynamic> location =
      widget.address?.json['locality_id'] != null
      ? Map<String, dynamic>.from(widget.address!.json)
      : {};
  @override
  void dispose() {
    for (final field in fields.values) {
      field.dispose();
    }
    super.dispose();
  }

  Future<void> save() async {
    if (!form.currentState!.validate()) return;
    if (location['locality_id'] == null) {
      setState(() => error = 'Select State, City and Locality before saving.');
      return;
    }
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final profile = await widget.api.saveAddress({
        'label': label,
        'isDefault': isDefault,
        ...location,
        for (final field in fields.entries) field.key: field.value.text.trim(),
      }, widget.address?.id);
      if (mounted) Navigator.pop(context, profile);
    } catch (e) {
      if (mounted) setState(() => error = friendlyError(e));
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => PopScope(
    canPop: !busy,
    child: Scaffold(
      appBar: AppBar(
        title: Text(widget.address == null ? 'New address' : 'Edit address'),
      ),
      body: ShopBody(
        children: [
          Form(
            key: form,
            child: Column(
              children: [
                DropdownButtonFormField<String>(
                  initialValue: label,
                  decoration: const InputDecoration(labelText: 'Address label'),
                  items: [
                    for (final name in ['Home', 'Work', 'Other'])
                      DropdownMenuItem(value: name, child: Text(name)),
                  ],
                  onChanged: busy
                      ? null
                      : (value) => setState(() => label = value!),
                ),
                const SizedBox(height: 16),
                for (final field in fields.entries)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 16),
                    child: TextFormField(
                      controller: field.value,
                      enabled: !busy,
                      maxLength: field.key == 'phone'
                          ? 10
                          : field.key == 'pin'
                          ? 6
                          : field.key == 'street'
                          ? 255
                          : 120,
                      keyboardType: ['phone', 'pin'].contains(field.key)
                          ? TextInputType.number
                          : TextInputType.streetAddress,
                      inputFormatters: ['phone', 'pin'].contains(field.key)
                          ? [FilteringTextInputFormatter.digitsOnly]
                          : null,
                      decoration: InputDecoration(
                        labelText: {
                          'name': 'Recipient name',
                          'phone': 'Mobile number',
                          'street': 'House, street and landmark',
                          'city': 'City',
                          'pin': 'Delivery PIN code',
                        }[field.key],
                      ),
                      validator: (value) {
                        final text = value?.trim() ?? '';
                        if (text.isEmpty) return 'This field is required';
                        if (field.key == 'phone' &&
                            !RegExp(r'^[6-9]\d{9}$').hasMatch(text)) {
                          return 'Enter a valid mobile number';
                        }
                        if (field.key == 'pin' &&
                            !RegExp(r'^[1-9]\d{5}$').hasMatch(text)) {
                          return 'Enter a valid 6-digit PIN code';
                        }
                        return null;
                      },
                    ),
                  ),
                LocationSelector(
                  api: widget.api,
                  localityId: location['locality_id'],
                  enabled: !busy,
                  onChanged: (value) => setState(() => location = value),
                ),
                const SizedBox(height: 12),
                SwitchListTile(
                  contentPadding: EdgeInsets.zero,
                  title: const Text('Make this my default address'),
                  value: isDefault,
                  onChanged: busy
                      ? null
                      : (value) => setState(() => isDefault = value),
                ),
              ],
            ),
          ),
          if (error != null) ErrorBox(error!),
          const SizedBox(height: 12),
          FilledButton(
            onPressed: busy ? null : save,
            child: Text(busy ? 'Saving…' : 'Save address'),
          ),
        ],
      ),
    ),
  );
}
