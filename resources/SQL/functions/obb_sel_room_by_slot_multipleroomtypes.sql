-- FUNCTION: salesforcetraining2.obb_sel_room_by_slot(character, timestamp without time zone, integer, character varying[])

-- DROP FUNCTION salesforcetraining2.obb_sel_room_by_slot(character, timestamp without time zone, integer, character varying[]);

CREATE OR REPLACE FUNCTION salesforcetraining2.obb_sel_room_by_slot(
	event_id character,
	_start_date timestamp without time zone,
	duration integer,
	room_type character varying[])
    RETURNS TABLE(room_id character varying, room_name character varying, start_time timestamp without time zone, end_time timestamp without time zone, out_room_type character varying) AS
  $BODY$

DECLARE
/*
    Author: YCH
    History: V1.0  Jul 02, 2015: Creation
             V1.1  Jul 13, 2015: Now ordering by room_name
             V1.2  Jul 20, 2015: Checking that _start_date is within the allowed time ranges
	     V1.3  Aug 27, 2015: Removed useless code for computing "matching" field
	     V1.4  Dec 16, 2015: Now returning the room types
	     V1.5  Jan 17, 2017: Replaced datediff with OVERLAPS
*/
	_start_date_endslot timestamp;
	_end_date timestamp;
	_availability record;
	_time_slot record;
	_avail_rooms integer;
	_nb_valid_timeslot integer;

BEGIN
	DROP table IF EXISTS _rooms;
	CREATE temporary TABLE _rooms (
		room_id character varying(18),
		room_name character varying(80),
		room_type character varying(255)
	);

	INSERT INTO _rooms (room_id, room_name, room_type) SELECT * from obb_sel_bilateral_rooms(event_id, room_type);

	DROP table IF EXISTS _event_timeslots;
	CREATE temporary TABLE  _event_timeslots (
		cur_day character varying(11),
		start_time timestamp,
		end_time timestamp,
		matching integer
	);


        _start_date_endslot = _start_date + duration * INTERVAL '1 minute';

	_nb_valid_timeslot := (SELECT count(*) from obb_sel_available_times(event_id, to_char(_start_date, 'YYYY-MM-DD'), duration, room_type) at where (at.start_time, at.end_time) OVERLAPS (_start_date, _start_date_endslot));
	IF (_nb_valid_timeslot > 0) THEN
		INSERT INTO _event_timeslots (cur_day, start_time, end_time, matching) SELECT to_char(_start_date, 'YYYY-MM-DD'), _start_date, _start_date_endslot, 0;
	END IF;





	DROP table IF EXISTS _room_timeslots;
	CREATE temporary TABLE  _room_timeslots (
		room_id character varying(18),
		start_time timestamp,
		end_time timestamp
	);
	INSERT INTO _room_timeslots (room_id, start_time, end_time) SELECT et.room_id, et.start_time, et.end_time FROM obb_sel_room_existing_timeslots(event_id, room_type) et;


	DROP table IF EXISTS _room_list;
	CREATE temporary TABLE  _room_list (
		room_id character varying(18),
		room_name character varying(80),
		start_time timestamp,
		end_time timestamp,
		room_type character varying(255)
	);





	FOR _time_slot IN
		SELECT * FROM _event_timeslots
	LOOP

		INSERT INTO _room_list (room_id, room_name, start_time, end_time, room_type) SELECT r.room_id, r.room_name, _time_slot.start_time, _time_slot.end_time, r.room_type FROM _rooms r WHERE r.room_id NOT IN (
			SELECT roo.room_id FROM _room_timeslots roo
			WHERE (_time_slot.start_time, _time_slot.end_time) OVERLAPS (roo.start_time, roo.end_time)
			);

                _avail_rooms = (SELECT count(*) FROM _room_list);

		IF _avail_rooms = 0 THEN
			DELETE FROM _event_timeslots ts WHERE ts.start_time = _time_slot.start_time;
			DELETE FROM _room_list rl WHERE rl.start_time = _time_slot.start_time;
		END IF;

	END LOOP;

	RETURN QUERY SELECT * from _room_list order by room_name;
END;

$BODY$
  LANGUAGE plpgsql VOLATILE
  COST 100
  ROWS 1000;

ALTER FUNCTION salesforcetraining2.obb_sel_room_by_slot(character, timestamp without time zone, integer, character varying[])
    OWNER TO u8dpm58fk1e5kk;

