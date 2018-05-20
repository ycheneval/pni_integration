-- Function: salesforcetraining2.obb_sel_times_bysession(character, integer)

-- DROP FUNCTION salesforcetraining2.obb_sel_times_bysession(character, integer);

CREATE OR REPLACE FUNCTION salesforcetraining2.obb_sel_times_bysession(IN session_id character, IN duration integer)
  RETURNS TABLE(day character varying, start_time timestamp without time zone, end_time timestamp without time zone, matching integer) AS
$BODY$
DECLARE
	_start_date timestamp;
	_start_date_endslot timestamp;
	_end_date timestamp;
	_availability record;
	_time_slot record;
	_avail_rooms integer;
	event_id character varying(18);
	_is_host_timeslot integer;
	
BEGIN

	DROP table IF EXISTS _event_timeslots;
	CREATE temporary TABLE  _event_timeslots (
		cur_day character varying(11),
		start_time timestamp,
		end_time timestamp,
		matching integer
	);

	event_id := (SELECT event__c FROM Session__c se WHERE se.sfid = session_id);

	-- Calculate all possible start time based on duration of each meeting
	-- Now we need to find the beginning of 
	INSERT INTO _event_timeslots (cur_day, start_time, end_time, matching) SELECT *, 0 from obb_sel_timeslots(event_id, duration);

	DROP table IF EXISTS _host_timeslots;
	CREATE temporary TABLE  _host_timeslots (
		cur_day character varying(11),
		start_time timestamp,
		end_time timestamp
	);
	INSERT INTO _host_timeslots (cur_day, start_time, end_time) SELECT * from obb_sel_timeslots_askedby_host(session_id, duration);

	-- Now go through all the timeslots and check if we have a timeslot available.
	-- To do that we count the remaining rooms that have the slot available. If 0, we
	-- remove the slot from the table
	FOR _time_slot IN
		SELECT * FROM _event_timeslots
	LOOP
		-- Is the current time slot free ?
		-- _time_slot.start_time;
		-- _avail_rooms := 1;
		_is_host_timeslot = LEAST(1, (SELECT COUNT(*) FROM _host_timeslots hts WHERE hts.start_time <= _time_slot.start_time AND  hts.end_time >= _time_slot.end_time));

		UPDATE _event_timeslots ets SET matching = _is_host_timeslot WHERE ets.start_time = _time_slot.start_time;

	END LOOP;
	
	--RETURN QUERY SELECT * from _rooms;
	RETURN QUERY SELECT * from _event_timeslots order by start_time;
END;
$BODY$
  LANGUAGE plpgsql VOLATILE
  COST 1000
  ROWS 100;
ALTER FUNCTION salesforcetraining2.obb_sel_times_bysession(character, integer, character)
  OWNER TO ue6lindbc6iam1;
COMMENT ON FUNCTION salesforcetraining2.obb_sel_times_bysession(character, integer, character) IS 'TBB Timeslots for whole Event with matching timeslots';


-- SET search_path = salesforcetraining2, public;
-- SELECT * FROM obb_sel_times_bysession('a0Wb0000002lQimEAE',15,'Public Figures')