<?php

/*
 * This file is part of Instrument package
 *
 * Copyright (c) 2016 Mika Tuupola
 *
 * Licensed under the MIT license:
 *   http://www.opensource.org/licenses/mit-license.php
 *
 * Project home:
 *   https://github.com/tuupola/instrument
 *
 */

namespace Instrument;

class InstrumentTest extends \PHPUnit_Framework_TestCase
{

    const DELTA = 10;

    public function testShouldCreateMetrics()
    {
        $instrument = new Instrument;
        $count = $instrument->count("users", 10);
        $this->assertEquals(10, $count->get());
        $this->assertEquals(10, $count->get("value"));
        $this->assertEquals(null, $count->get("nosuch"));

        $timing = $instrument->timing("roundtrip")->set("loadtime", 1432);
        $this->assertEquals(null, $timing->get());
        $this->assertEquals(null, $timing->get("value"));
        $this->assertEquals(1432, $timing->get("loadtime"));

        $gauge = $instrument->gauge("tickets", 9923);
        $this->assertEquals(9923, $gauge->get());
        $this->assertEquals(9923, $gauge->get("value"));
        $this->assertEquals(null, $gauge->get("nosuch"));

        $event = $instrument->event("deploy", "Deployed by dopevs");
        $this->assertEquals("deploy", $event->get());
        $this->assertEquals("deploy", $event->get("title"));
        $this->assertEquals("Deployed by dopevs", $event->get("description"));
        $this->assertEquals(null, $event->get("nosuch"));
    }

    public function testShouldGetAndSetTransformer()
    {
        $instrument = new Instrument([
            "transformer" => new Transformer\InfluxDB
        ]);
        $this->assertInstanceOf("Instrument\Transformer\InfluxDB", $instrument->transformer());
    }

    public function testShouldGetAndSetAdapter()
    {
        $instrument = new Instrument([
            "adapter" => new Adapter\Memory
        ]);
        $this->assertInstanceOf("Instrument\Adapter\Memory", $instrument->adapter());
    }

    public function testShouldSend()
    {
        $instrument = new Instrument([
            "transformer" => new Transformer\InfluxDB,
            "adapter" => new Adapter\Memory
        ]);

        $count = $instrument->count("users", 10);
        $timing = $instrument->timing("roundtrip")->set("loadtime", 1432);
        $gauge = $instrument->gauge("tickets", 9923);
        $event = $instrument->event("deploy", "Deployed by dopevs");
        $event = $instrument->event("deploy", "Deployed again by dopevs");

        $instrument->send();
        $adapter = $instrument->adapter();
        $sent = $adapter->measurements();

        $this->assertEquals("users", $sent["users"]->getMeasurement());
        $this->assertEquals("roundtrip", $sent["roundtrip"]->getMeasurement());
        $this->assertEquals("events", $sent["events-0"]->getMeasurement());
        $this->assertEquals("events", $sent["events-1"]->getMeasurement());
    }

    public function testShouldClearAfterSend()
    {
        $instrument = new Instrument([
            "transformer" => new Transformer\InfluxDB,
            "adapter" => new Adapter\Memory
        ]);

        $count = $instrument->count("users", 10);
        $timing = $instrument->timing("roundtrip")->set("loadtime", 1432);
        $gauge = $instrument->gauge("tickets", 9923);
        $event = $instrument->event("deploy", "Deployed by dopevs");
        $event = $instrument->event("deploy", "Deployed again by dopevs");

        $measurements = $instrument->measurements();
        $events = $instrument->events();

        $this->assertEquals(3, count($measurements));
        $this->assertEquals(2, count($events));

        $instrument->send();

        $measurements = $instrument->measurements();
        $events = $instrument->events();

        $this->assertEquals(0, count($measurements));
        $this->assertEquals(0, count($events));
    }

    public function testShouldSendSingle()
    {
        $instrument = new Instrument([
            "transformer" => new Transformer\InfluxDB,
            "adapter" => new Adapter\Memory
        ]);

        $count = $instrument->count("users", 10);
        $timing = $instrument->timing("roundtrip")->set("loadtime", 1432);
        $event = $instrument->event("deploy", "Deployed by dopevs");
        $event = $instrument->event("deploy", "Deployed again by dopevs");

        $instrument->send($timing);
        $adapter = $instrument->adapter();
        $sent = $adapter->measurements();

        $this->assertArrayNotHasKey("users", $sent);
        $this->assertArrayNotHasKey("events-0", $sent);
        $this->assertArrayNotHasKey("events-1", $sent);

        $this->assertEquals("roundtrip", $sent["roundtrip"]->getMeasurement());
    }

    public function testShouldStartAndStopChainedTimer()
    {
        $instrument = new Instrument;

        $instrument->timing("test")->start();
        usleep(10000);
        $instrument->timing("test")->stop();

        $instrument->timing("test")->start("jump");
        usleep(20000);
        $instrument->timing("test")->stop("jump");

        $this->assertEquals($instrument->timing("test")->get(), 10, null, self::DELTA);
        $this->assertEquals($instrument->timing("test")->get("jump"), 20, null, self::DELTA);
    }

    public function testShouldMeasureChainedClosure()
    {
        $instrument = new Instrument;
        $instrument->timing("test")->set(function () {
            usleep(10000);
        });
        $instrument->timing("test")->set("dive", function () {
            usleep(20000);
        });

        $this->assertEquals($instrument->timing("test")->get(), 10, null, self::DELTA);
        $this->assertEquals($instrument->timing("test")->get("dive"), 20, null, self::DELTA);
    }

    public function testShouldReturnExistingMeasurement()
    {
        $instrument = new Instrument;
        $instrument->count("cars")->set("ford", 10);
        $count = $instrument->count("cars")->set("audi", 20);
        $this->assertEquals(10, $count->get("ford"));
        $this->assertEquals(20, $count->get("audi"));
        $this->assertEquals(null, $count->get("nosuch"));

        $instrument->timing("boot")->set("first", 1230);
        $timing = $instrument->timing("boot")->set("second", 20);
        $this->assertEquals(1230, $timing->get("first"));
        $this->assertEquals(20, $timing->get("second"));
        $this->assertEquals(null, $count->get("nosuch"));

        $instrument->gauge("users")->set("online", 992);
        $gauge = $instrument->gauge("users")->set("registered", 651237);
        $this->assertEquals(992, $gauge->get("online"));
        $this->assertEquals(651237, $gauge->get("registered"));
        $this->assertEquals(null, $gauge->get("nosuch"));
    }

    public function testShouldReturnByReference()
    {
        $instrument = new Instrument;
        $count = $instrument->count("cars")->set("ford", 10);
        $count->set("audi", 20);
        $count->set("bmw", 30);

        $this->assertEquals(10, $instrument->count("cars")->get("ford"));
        $this->assertEquals(20, $instrument->count("cars")->get("audi"));
        $this->assertEquals(30, $instrument->count("cars")->get("bmw"));
        $this->assertEquals(null, $instrument->count("cars")->get("nosuch"));
    }

    public function testShouldDeleteOneMeasurement()
    {
        $instrument = new Instrument;

        $users = $instrument->count("users", 10);
        $errors = $instrument->count("errors", 100);
        $timing = $instrument->timing("roundtrip")->set("loadtime", 1432);

        $measurements = $instrument->measurements();

        $this->assertEquals(3, count($measurements));
        $this->assertEquals(100, $measurements["errors"]->value());

        $instrument->delete($errors);
        $measurements = $instrument->measurements();
        $this->assertEquals(2, count($measurements));
        $this->assertArrayNotHasKey("errors", $measurements);
    }

    public function testShouldDeleteOneEvent()
    {
        $instrument = new Instrument;

        $event1 = $instrument->event("deploy", "Deployed by dopevs");
        $event2 = $instrument->event("deploy", "Deployed again by dopevs");
        $event3 = $instrument->event("deploy", "Impossibru!");

        $events = $instrument->events();

        $this->assertEquals(3, count($events));

        $instrument->delete($event2);
        $events = $instrument->events();

        $this->assertEquals(2, count($events));
        $this->assertEquals("Deployed by dopevs", $events[0]->get("description"));
        $this->assertArrayNotHasKey(1, $events);
        $this->assertEquals("Impossibru!", $events[2]->get("description"));
    }

    public function testShouldStopTimers()
    {
        $instrument = new Instrument;

        $instrument->timing("roundtrip")->start("first");
        usleep(10000);
        $instrument->timing("roundtrip")->start("second");
        $instrument->timing("bootstrap")->start();
        usleep(10000);

        $this->assertNull($instrument->timing("roundtrip")->get("first"));
        $this->assertNull($instrument->timing("roundtrip")->get("second"));
        $this->assertNull($instrument->timing("bootstrap")->get());

        $instrument->stopTimers();

        $this->assertEquals($instrument->timing("roundtrip")->get("first"), 20, null, self::DELTA);
        $this->assertEquals($instrument->timing("roundtrip")->get("second"), 10, null, self::DELTA);
        $this->assertEquals($instrument->timing("bootstrap")->get(), 10, null, self::DELTA);
    }
}
