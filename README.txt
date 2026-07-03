REAL RESERVATION DATA VERSION

This project supports the flat JSON structure supplied in real_reservations.json.
The included data file has been renamed to reservations.json because the watcher
and application use that filename.

Supported fields include:
- pickup and return routes/dates/times
- customer name, email and phone
- adults, children, luggage and child_seats
- vehicle and driver_id
- final_price and original price
- payment status and payment method
- one or multiple flights from flight_details
- customer message and created_at

The importer also remains compatible with the previous nested demo JSON format.

Copy all files into the same XAMPP htdocs project folder. Apache and MySQL must
be running. Existing database tables are upgraded automatically with the new
columns, and the JSON is resynchronized once after the schema update.
